<?php
/**
 * Basic MCP SaaS Server example using Slim Framework 4
 *
 * This example demonstrates:
 * - Database storage with authentication
 * - Custom tools and prompts
 * - Resource management
 * - OAuth discovery endpoints
 *
 * Prerequisites:
 * 1. Install dependencies: composer require slim/slim slim/psr7 seolinkmap/wassup
 * 2. Set up database and import schema from examples/database/database-schema.sql
 * 3. Update database credentials below
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory};
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Database connection - UPDATE THESE CREDENTIALS
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'mcp_server';
$username = getenv('DB_USER') ?: 'your_username';
$password = getenv('DB_PASS') ?: 'your_password';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\nPlease check your database credentials and ensure the database exists.\n");
}

// Initialize storage
$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);

// Initialize registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration
$config = [
    'server_info' => [
        'name' => 'Basic MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => getenv('APP_URL') ?: 'http://localhost:8080'
    ],
    'discovery' => [
        'scopes_supported' => ['mcp:read', 'mcp:write']
    ],
    'sse' => [
        'keepalive_interval' => 1,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60
    ]
];

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));

// Register custom tools
$toolRegistry->register('echo', function($params, $context) {
    return [
        'message' => $params['message'] ?? 'Hello!',
        'received_params' => $params,
        'context_available' => !empty($context),
        'agency_name' => $context['context_data']['name'] ?? 'Unknown',
        'timestamp' => date('c')
    ];
}, [
    'description' => 'Echo a message back with context information',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'message' => [
                'type' => 'string',
                'description' => 'Message to echo back'
            ]
        ],
        'required' => ['message']
    ]
]);

$toolRegistry->register('get_time', function($params, $context) {
    $timezone = $params['timezone'] ?? 'UTC';

    try {
        $date = new DateTime('now', new DateTimeZone($timezone));
        return [
            'current_time' => $date->format('c'),
            'timezone' => $timezone,
            'unix_timestamp' => $date->getTimestamp(),
            'formatted' => $date->format('Y-m-d H:i:s T')
        ];
    } catch (Exception $e) {
        return [
            'error' => 'Invalid timezone',
            'timezone' => $timezone,
            'available_timezones' => ['UTC', 'America/New_York', 'Europe/London', 'Asia/Tokyo']
        ];
    }
}, [
    'description' => 'Get current time in specified timezone',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'timezone' => [
                'type' => 'string',
                'description' => 'Timezone identifier (e.g., "America/New_York")',
                'default' => 'UTC'
            ]
        ]
    ]
]);

$toolRegistry->register('system_info', function($params, $context) {
    return [
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'server_time' => date('c'),
        'timezone' => date_default_timezone_get(),
        'agency' => $context['context_data']['name'] ?? 'Unknown'
    ];
}, [
    'description' => 'Get system information',
    'inputSchema' => ['type' => 'object']
]);

// Register custom prompts
$promptRegistry->register('greeting', function($arguments, $context) {
    $name = $arguments['name'] ?? 'there';
    $style = $arguments['style'] ?? 'friendly';

    $greetings = [
        'friendly' => "Please greet {$name} in a warm and friendly way.",
        'formal' => "Please provide a formal and professional greeting to {$name}.",
        'casual' => "Say hi to {$name} in a casual, relaxed manner."
    ];

    return [
        'description' => 'A customizable greeting prompt',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $greetings[$style] ?? $greetings['friendly']
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate a customizable greeting prompt',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Name of the person to greet'
            ],
            'style' => [
                'type' => 'string',
                'enum' => ['friendly', 'formal', 'casual'],
                'description' => 'Style of greeting',
                'default' => 'friendly'
            ]
        ]
    ]
]);

$promptRegistry->register('help', function($arguments, $context) {
    $topic = $arguments['topic'] ?? 'general';

    return [
        'description' => 'Help prompt for MCP server features',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Help me with {$topic} on this MCP server. What tools and features are available?"
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate help prompts for various topics',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'Topic to get help with']
        ]
    ]
]);

// Register custom resources
$resourceRegistry->register('server://status', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode([
                    'status' => 'healthy',
                    'timestamp' => date('c'),
                    'uptime' => time() - $_SERVER['REQUEST_TIME'],
                    'agency' => $context['context_data']['name'] ?? 'Unknown',
                    'php_version' => PHP_VERSION,
                    'memory_usage' => memory_get_usage(true)
                ], JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Server Status',
    'description' => 'Current server status and health information',
    'mimeType' => 'application/json'
]);

$resourceRegistry->register('server://info', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "MCP SaaS Server\n" .
                         "===============\n" .
                         "Version: 1.0.0\n" .
                         "Protocol: 2024-11-05\n" .
                         "Agency: " . ($context['context_data']['name'] ?? 'Unknown') . "\n" .
                         "Timestamp: " . date('c') . "\n"
            ]
        ]
    ];
}, [
    'name' => 'Server Information',
    'description' => 'Basic server information',
    'mimeType' => 'text/plain'
]);

// Register resource template for dynamic file access
$resourceRegistry->registerTemplate('file://{path}', function($uri, $context) {
    $path = str_replace('file://', '', $uri);
    $safePath = basename($path); // Basic security measure

    // In a real implementation, you'd want proper path validation and file reading
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "Content for file: {$safePath}\n(This is a demo - implement actual file reading with proper security)"
            ]
        ]
    ];
}, [
    'name' => 'File Resource',
    'description' => 'Read file contents from the server',
    'mimeType' => 'text/plain'
]);

// PSR-17 factories
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();

// Create MCP provider
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config
);

// Create Slim app
$app = AppFactory::create();

// Add error middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Add CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
});

// OAuth discovery endpoints
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

$app->get('/.well-known/oauth-protected-resource',
    [$mcpProvider, 'handleResourceDiscovery']);

// Main MCP endpoint with authentication
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

// Health check endpoint (no auth required)
$app->get('/health', function (Request $request, Response $response) {
    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'version' => '1.0.0',
        'database' => 'connected'
    ];

    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});

// Welcome page with setup instructions
$app->get('/', function (Request $request, Response $response) {
    $html = "<!DOCTYPE html>
<html>
<head>
    <title>MCP SaaS Server</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        .endpoint { background: #f5f5f5; padding: 15px; margin: 10px 0; border-radius: 5px; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; }
        .warning { background: #fff3cd; padding: 12px; border-radius: 4px; border: 1px solid #ffeaa7; }
    </style>
</head>
<body>
    <h1>MCP SaaS Server</h1>
    <p>Your MCP server is running successfully!</p>

    <div class='warning'>
        <strong>Note:</strong> This server requires OAuth authentication. You'll need to set up OAuth clients and tokens before using the MCP endpoints.
    </div>

    <h2>Endpoints</h2>
    <div class='endpoint'>
        <strong>MCP Endpoint:</strong> <code>/mcp/{agencyUuid}</code><br>
        <strong>OAuth Discovery:</strong> <code>/.well-known/oauth-authorization-server</code><br>
        <strong>Health Check:</strong> <code>/health</code>
    </div>

    <h2>Quick Tests</h2>
    <ul>
        <li><a href='/health'>Check server health</a></li>
        <li><a href='/.well-known/oauth-authorization-server'>View OAuth configuration</a></li>
    </ul>

    <h2>Available Tools</h2>
    <ul>
        <li><strong>ping</strong> - Test connectivity</li>
        <li><strong>server_info</strong> - Get server information</li>
        <li><strong>echo</strong> - Echo messages with context</li>
        <li><strong>get_time</strong> - Get current time in any timezone</li>
        <li><strong>system_info</strong> - Get system information</li>
    </ul>

    <h2>Setup Instructions</h2>
    <ol>
        <li>Import the database schema from <code>examples/database/database-schema.sql</code></li>
        <li>Update database credentials in this file</li>
        <li>Create OAuth clients using the registration endpoint or database</li>
        <li>Use the sample token 'test-token-12345' for testing</li>
    </ol>
</body>
</html>";

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// Start server
echo "Starting MCP SaaS Server on http://localhost:8080\n";
echo "Health check: http://localhost:8080/health\n";
echo "OAuth discovery: http://localhost:8080/.well-known/oauth-authorization-server\n";
echo "\nEnsure you have:\n";
echo "1. Database set up with schema from examples/database/database-schema.sql\n";
echo "2. Updated database credentials in this file\n";
echo "3. Created OAuth clients and tokens for authentication\n";

$app->run();
