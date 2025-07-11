<?php
/**
 * Public MCP Server Example (No Authentication Required)
 *
 * Prerequisites:
 * 1. Install dependencies: composer require slim/slim slim/psr7 seolinkmap/wassup
 * 2. No database required (uses memory storage)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory};
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Create memory storage with pre-configured context
$storage = new MemoryStorage();
$storage->addContext('public', 'agency', [
    'id' => 1,
    'name' => 'Public MCP Server',
    'active' => true
]);

// Add a test token for public access
$storage->addToken('public-token', [
    'access_token' => 'public-token',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 86400,
    'revoked' => false
]);

// Initialize registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration
$config = [
    'server_info' => [
        'name' => 'Public Repository Explorer',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'http://localhost:8080'
    ],
    'sse' => [
        'test_mode' => false,
        'keepalive_interval' => 2,
        'max_connection_time' => 600
    ]
];

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));

// Register custom tools
$toolRegistry->register('get_server_info', function($params, $context) {
    return [
        'server' => 'Public MCP Repository Explorer',
        'version' => '1.0.0',
        'description' => 'Explore this repository and public data through MCP',
        'endpoints' => [
            'browse_repository' => 'Browse directory structure',
            'read_file' => 'Read file contents',
            'search_files' => 'Search for files',
            'get_weather' => 'Get weather information',
            'calculate' => 'Perform basic calculations'
        ],
        'note' => 'This is a public demo server - no authentication required'
    ];
}, [
    'description' => 'Get information about this public MCP server',
    'inputSchema' => ['type' => 'object']
]);

$toolRegistry->register('browse_repository', function($params, $context) {
    $path = $params['path'] ?? '';
    $basePath = __DIR__ . '/../../';

    $fullPath = realpath($basePath . $path);
    if (!$fullPath || !str_starts_with($fullPath, realpath($basePath))) {
        return ['error' => 'Path outside repository'];
    }

    if (is_dir($fullPath)) {
        $items = [];
        foreach (scandir($fullPath) as $item) {
            if ($item[0] === '.') continue;
            $itemPath = $fullPath . '/' . $item;
            $items[] = [
                'name' => $item,
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'path' => ltrim($path . '/' . $item, '/'),
                'size' => is_file($itemPath) ? filesize($itemPath) : null
            ];
        }
        return ['path' => $path ?: 'root', 'items' => $items];
    }

    return ['error' => 'Not a directory'];
}, [
    'description' => 'Browse repository directory structure',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Directory path to browse']
        ]
    ]
]);

$toolRegistry->register('read_file', function($params, $context) {
    $path = $params['path'];
    $basePath = __DIR__ . '/../../';
    $fullPath = realpath($basePath . $path);

    if (!$fullPath || !str_starts_with($fullPath, realpath($basePath)) ||
        !file_exists($fullPath) || !is_file($fullPath)) {
        return ['error' => 'File not found or access denied'];
    }

    if (filesize($fullPath) > 500000) {
        return ['error' => 'File too large to display (>500KB)'];
    }

    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $textExtensions = ['txt', 'md', 'php', 'js', 'css', 'html', 'json', 'xml', 'yml', 'yaml', 'sql'];

    if (!in_array($extension, $textExtensions)) {
        return ['error' => 'Binary file or unsupported format'];
    }

    return [
        'path' => $path,
        'content' => file_get_contents($fullPath),
        'size' => filesize($fullPath),
        'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
        'extension' => $extension
    ];
}, [
    'description' => 'Read contents of a repository file',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'File path to read']
        ],
        'required' => ['path']
    ]
]);

$toolRegistry->register('get_weather', function($params, $context) {
    $city = $params['city'] ?? 'London';

    $weatherData = [
        'London' => ['temp' => '15°C', 'condition' => 'Cloudy'],
        'New York' => ['temp' => '22°C', 'condition' => 'Sunny'],
        'Tokyo' => ['temp' => '18°C', 'condition' => 'Rainy'],
        'Sydney' => ['temp' => '25°C', 'condition' => 'Clear']
    ];

    $weather = $weatherData[$city] ?? $weatherData['London'];

    return [
        'city' => $city,
        'temperature' => $weather['temp'],
        'condition' => $weather['condition'],
        'timestamp' => date('c'),
        'note' => 'This is mock data for demo purposes'
    ];
}, [
    'description' => 'Get weather information for a city',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name']
        ]
    ]
]);

$toolRegistry->register('calculate', function($params, $context) {
    $expression = $params['expression'] ?? '';

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
$promptRegistry->register('exploration_guide', function($arguments, $context) {
    $topic = $arguments['topic'] ?? 'the repository';

    return [
        'description' => 'Guide for exploring the repository',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Help me explore {$topic}. What should I look at first and what tools are available?"
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate an exploration guide prompt',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'What to explore']
        ]
    ]
]);

// Register resources
$resourceRegistry->register('server://about', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "Public MCP Server Demo\n" .
                         "====================\n\n" .
                         "This is a demonstration of a public MCP server that requires no authentication.\n" .
                         "You can explore the repository structure, read files, and use various tools.\n\n" .
                         "Available features:\n" .
                         "- Repository browsing\n" .
                         "- File reading\n" .
                         "- Weather information\n" .
                         "- Basic calculations\n\n" .
                         "This server uses memory storage and includes rate limiting for security."
            ]
        ]
    ];
}, [
    'name' => 'About This Server',
    'description' => 'Information about this public MCP server',
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

// CORS middleware for web clients
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Mcp-Session-Id')
        ->withHeader('Access-Control-Max-Age', '3600');
});

// Security headers
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('X-XSS-Protection', '1; mode=block');
});

// Public MCP endpoint - no authentication required
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/public[/{sessID}]', function (Request $request, Response $response) use ($mcpProvider) {
    // Add public context to request
    $request = $request->withAttribute('mcp_context', [
        'context_data' => ['id' => 1, 'name' => 'Public', 'active' => true],
        'token_data' => ['user_id' => 1, 'scope' => 'mcp:read'],
        'context_id' => 'public',
        'base_url' => $request->getUri()->getScheme() . '://' . $request->getUri()->getHost()
    ]);

    return $mcpProvider->handleMCP($request, $response);
});

// Welcome page
$app->get('/', function (Request $request, Response $response) {
    $html = "<!DOCTYPE html>
<html>
<head>
    <title>Public MCP Server Demo</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        .endpoint { background: #f5f5f5; padding: 15px; margin: 10px 0; border-radius: 5px; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Public MCP Server Demo</h1>
    <p>This is a demonstration of a public MCP server that requires no authentication.</p>

    <h2>MCP Endpoint</h2>
    <div class='endpoint'>
        <strong>Endpoint:</strong> <code>/mcp/public</code><br>
        <strong>Methods:</strong> GET (SSE), POST (commands)<br>
        <strong>Authentication:</strong> None required
    </div>

    <h2>Quick Test</h2>
    <p>Try these commands to test the server:</p>
    <ul>
        <li><code>curl -X POST http://localhost:8080/mcp/public -H 'Content-Type: application/json' -d '{\"jsonrpc\":\"2.0\",\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2024-11-05\",\"clientInfo\":{\"name\":\"Test\"}},\"id\":1}'</code></li>
        <li>Use the session ID from the response to make further requests</li>
    </ul>

    <h2>Available Tools</h2>
    <ul>
        <li><strong>get_server_info</strong> - Information about this server</li>
        <li><strong>browse_repository</strong> - Browse directory structure</li>
        <li><strong>read_file</strong> - Read file contents</li>
        <li><strong>get_weather</strong> - Get weather information</li>
        <li><strong>calculate</strong> - Perform basic calculations</li>
    </ul>
</body>
</html>";

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// Health check
$app->get('/health', function (Request $request, Response $response) {
    $health = [
        'status' => 'healthy',
        'type' => 'public',
        'timestamp' => date('c'),
        'version' => '1.0.0'
    ];

    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});

echo "Starting Public MCP Server on http://localhost:8080\n";
echo "Visit http://localhost:8080 for instructions\n";
echo "MCP endpoint: http://localhost:8080/mcp/public\n";

$app->run();
