<?php
/**
 * Standalone MCP SaaS Server Example
 *
 * This example shows how to use the MCP server without any framework.
 * Uses memory storage for simplicity (no database required).
 *
 * Prerequisites:
 * 1. composer require seolinkmap/wassup slim/psr7
 * 2. php -S localhost:8080 simple-server.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory, ServerRequestFactory, UriFactory};

// Create memory storage with test data
$storage = new MemoryStorage();
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1,
    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'name' => 'Test Agency',
    'active' => true
]);
$storage->addToken('test-token-12345', [
    'access_token' => 'test-token-12345',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 3600,
    'revoked' => false
]);

// Initialize registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration
$config = [
    'server_info' => [
        'name' => 'Standalone MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'http://localhost:8080'
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
        'message' => $params['message'] ?? 'Hello from standalone MCP server!',
        'received_params' => $params,
        'context_available' => !empty($context),
        'agency_name' => $context['context_data']['name'] ?? 'Unknown',
        'timestamp' => date('c')
    ];
}, [
    'description' => 'Echo a message back with context',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'Message to echo']
        ],
        'required' => ['message']
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

$toolRegistry->register('calculate', function($params, $context) {
    $expression = $params['expression'] ?? '';

    // Basic validation - only allow numbers and basic operators
    if (!preg_match('/^[0-9+\-*\/\.\(\)\s]+$/', $expression)) {
        return ['error' => 'Invalid expression - only basic math allowed'];
    }

    try {
        $result = eval("return $expression;");
        return [
            'expression' => $expression,
            'result' => $result,
            'timestamp' => date('c')
        ];
    } catch (ParseError $e) {
        return ['error' => 'Invalid mathematical expression'];
    } catch (Error $e) {
        return ['error' => 'Calculation error'];
    }
}, [
    'description' => 'Perform basic mathematical calculations',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'expression' => ['type' => 'string', 'description' => 'Mathematical expression (e.g., "2 + 3 * 4")']
        ],
        'required' => ['expression']
    ]
]);

// Register prompts
$promptRegistry->register('help', function($arguments, $context) {
    $topic = $arguments['topic'] ?? 'general';

    return [
        'description' => 'Help prompt for standalone server',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Help me with {$topic} on this standalone MCP server. What tools are available?"
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate help prompts',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'Topic to get help with']
        ]
    ]
]);

$promptRegistry->register('greeting', function($arguments, $context) {
    $name = $arguments['name'] ?? 'there';
    $agency = $context['context_data']['name'] ?? 'Unknown Agency';

    return [
        'description' => 'Friendly greeting prompt',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Please greet {$name} from {$agency} in a friendly way and tell them about this MCP server."
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate friendly greeting prompts',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Name to greet']
        ]
    ]
]);

// Register resources
$resourceRegistry->register('server://status', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode([
                    'status' => 'healthy',
                    'server_type' => 'standalone',
                    'timestamp' => date('c'),
                    'uptime' => time() - $_SERVER['REQUEST_TIME'],
                    'memory_usage' => memory_get_usage(true),
                    'agency' => $context['context_data']['name'] ?? 'Unknown'
                ], JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Server Status',
    'description' => 'Current server status',
    'mimeType' => 'application/json'
]);

$resourceRegistry->register('server://info', function($uri, $context) {
    global $config;

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "Standalone MCP Server\n" .
                         "====================\n" .
                         "Version: " . ($config['server_info']['version'] ?? '1.0.0') . "\n" .
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

// PSR-17 factories
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();
$requestFactory = new ServerRequestFactory();
$uriFactory = new UriFactory();

// Create MCP server
$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $config
);

// Create auth middleware
$authMiddleware = new AuthMiddleware(
    $storage,
    $responseFactory,
    $streamFactory,
    $config['auth']
);

// Simple authentication helper
function authenticateRequest(array $headers, MemoryStorage $storage): ?array {
    $authHeader = '';
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authHeader = is_array($value) ? $value[0] : $value;
            break;
        }
    }

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
        $tokenData = $storage->validateToken($token);
        if ($tokenData) {
            $contextData = $storage->getContextData('550e8400-e29b-41d4-a716-446655440000', 'agency');
            if ($contextData) {
                return [
                    'context_data' => $contextData,
                    'token_data' => $tokenData,
                    'context_id' => '550e8400-e29b-41d4-a716-446655440000',
                    'base_url' => 'http://localhost:8080'
                ];
            }
        }
    }
    return null;
}

// Handle incoming requests
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = getallheaders() ?: [];

// Create PSR-7 request
$psr7Uri = $uriFactory->createUri($_SERVER['REQUEST_URI']);
$request = $requestFactory->createServerRequest($method, $psr7Uri, $_SERVER);

// Add headers
foreach ($headers as $name => $value) {
    $request = $request->withHeader($name, $value);
}

// Add body for POST requests
if ($method === 'POST') {
    $body = $streamFactory->createStream(file_get_contents('php://input'));
    $request = $request->withBody($body);
}

// Create PSR-7 response
$response = $responseFactory->createResponse();

// Route handling
if ($uri === '/health') {
    // Health check endpoint
    $health = [
        'status' => 'healthy',
        'type' => 'standalone',
        'timestamp' => date('c'),
        'version' => '1.0.0',
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true)
    ];
    $response->getBody()->write(json_encode($health));
    $response = $response->withHeader('Content-Type', 'application/json');

} elseif (preg_match('/^\/mcp\/([^\/]+)(?:\/([^\/]+))?$/', $uri, $matches)) {
    // MCP endpoint with authentication
    $agencyUuid = $matches[1];
    $sessionId = $matches[2] ?? null;

    $authContext = authenticateRequest($headers, $storage);

    if (!$authContext) {
        // Return authentication required error
        $errorResponse = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => 'Authentication required',
                'data' => [
                    'oauth' => [
                        'authorization_endpoint' => 'http://localhost:8080/oauth/authorize',
                        'token_endpoint' => 'http://localhost:8080/oauth/token'
                    ]
                ]
            ],
            'id' => null
        ];

        $response->getBody()->write(json_encode($errorResponse));
        $response = $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="MCP Server"')
            ->withStatus(401);
    } else {
        // Add authentication context to request
        $request = $request->withAttribute('mcp_context', $authContext);

        // Handle with MCP server
        $response = $mcpServer->handle($request, $response);
    }

} elseif ($uri === '/') {
    // Welcome page
    $html = "<!DOCTYPE html>
<html>
<head>
    <title>Standalone MCP Server</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        .endpoint { background: #f5f5f5; padding: 15px; margin: 10px 0; border-radius: 5px; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
        .token { background: #e8f5e8; padding: 10px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>Standalone MCP Server</h1>
    <p>This is a standalone MCP server running without any framework.</p>

    <div class='token'>
        <strong>Test Token:</strong> <code>test-token-12345</code><br>
        <small>Use this token in the Authorization header: <code>Bearer test-token-12345</code></small>
    </div>

    <h2>Endpoints:</h2>
    <div class='endpoint'>
        <strong>MCP Endpoint:</strong> <code>/mcp/550e8400-e29b-41d4-a716-446655440000</code><br>
        <strong>Health Check:</strong> <a href='/health'><code>/health</code></a>
    </div>

    <h2>Quick Test:</h2>
    <p>Test the MCP endpoint with curl:</p>
    <code style='display:block;background:#f0f0f0;padding:10px;margin:10px 0;'>
curl -X POST http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \\<br>
&nbsp;&nbsp;-H 'Content-Type: application/json' \\<br>
&nbsp;&nbsp;-H 'Authorization: Bearer test-token-12345' \\<br>
&nbsp;&nbsp;-d '{\"jsonrpc\":\"2.0\",\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2024-11-05\",\"clientInfo\":{\"name\":\"Test\"}},\"id\":1}'
    </code>

    <h2>Available Tools:</h2>
    <ul>
        <li><strong>ping</strong> - Test connectivity to the server</li>
        <li><strong>server_info</strong> - Get server information</li>
        <li><strong>echo</strong> - Echo messages with context</li>
        <li><strong>system_info</strong> - Get system information</li>
        <li><strong>calculate</strong> - Perform basic calculations</li>
    </ul>

    <h2>Usage:</h2>
    <ol>
        <li>Use the test token <code>test-token-12345</code> in Authorization header</li>
        <li>Send POST requests to <code>/mcp/550e8400-e29b-41d4-a716-446655440000</code></li>
        <li>Follow MCP protocol format for JSON-RPC messages</li>
    </ol>
</body>
</html>";

    $response->getBody()->write($html);
    $response = $response->withHeader('Content-Type', 'text/html');

} else {
    // 404 for other routes
    $response = $response->withStatus(404);
    $response->getBody()->write('Not Found');
}

// Add CORS headers
$response = $response
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id');

// Send response
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();

// Usage instructions when run from command line
if (php_sapi_name() === 'cli') {
    echo "\n\nStarting standalone MCP server...\n";
    echo "Run: php -S localhost:8080 " . __FILE__ . "\n";
    echo "Visit: http://localhost:8080\n";
    echo "Test: curl http://localhost:8080/health\n";
    echo "\nAuthentication:\n";
    echo "Token: test-token-12345\n";
    echo "Agency UUID: 550e8400-e29b-41d4-a716-446655440000\n";
}
