<?php
/**
 * Production MCP SaaS Server using Slim Framework 4
 *
 * Features:
 * - OAuth 2.1 with RFC 8707 Resource Indicators (MCP 2025-06-18)
 * - Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)
 * - Database storage with authentication
 * - Social authentication (Google, LinkedIn, GitHub)
 * - Tool annotations and audio content support
 * - Server-Sent Events and Streamable HTTP transport
 *
 * Prerequisites:
 * 1. composer require slim/slim slim/psr7 seolinkmap/waasup monolog/monolog
 * 2. Set up database and import schema from examples/database/database-schema.sql
 * 3. Configure environment variables
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory};
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Content\AudioContentHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// Environment configuration
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'mcp_server';
$username = $_ENV['DB_USER'] ?? 'your_username';
$password = $_ENV['DB_PASS'] ?? 'your_password';
$baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';

// Database connection with error handling
try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\nPlease check your database credentials.\n");
}

// Initialize logger
$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Initialize storage
$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);

// Initialize registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration with full protocol support
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Production MCP Server',
        'version' => '1.1.0'
    ],
    'auth' => [
        'context_types' => ['agency', 'user'],
        'base_url' => $baseUrl,
        'validate_scope' => true,
        'required_scopes' => ['mcp:read']
    ],
    'discovery' => [
        'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:admin']
    ],
    'sse' => [
        'keepalive_interval' => 1,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60
    ],
    'streamable_http' => [
        'keepalive_interval' => 2,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60
    ],
    // Social authentication configuration
    'google' => [
        'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $baseUrl . '/oauth/google/callback'
    ],
    'linkedin' => [
        'client_id' => $_ENV['LINKEDIN_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['LINKEDIN_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $baseUrl . '/oauth/linkedin/callback'
    ],
    'github' => [
        'client_id' => $_ENV['GITHUB_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['GITHUB_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $baseUrl . '/oauth/github/callback'
    ]
];

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));

// Register advanced tools with protocol version awareness
$toolRegistry->register('echo_advanced', function($params, $context) {
    $message = $params['message'] ?? 'Hello from MCP!';
    $includeAudio = $params['include_audio'] ?? false;

    $result = [
        'echo' => $message,
        'timestamp' => date('c'),
        'agency' => $context['context_data']['name'] ?? 'Unknown',
        'protocol_version' => $context['protocol_version'] ?? '2024-11-05'
    ];

    $content = [
        ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]
    ];

    // Audio content only supported in 2025-03-26+
    if ($includeAudio && in_array($context['protocol_version'] ?? '2024-11-05', ['2025-03-26', '2025-06-18'])) {
        try {
            // Create a simple audio beep (mock implementation)
            $audioData = base64_encode('mock_audio_data');
            $content[] = [
                'type' => 'audio',
                'mimeType' => 'audio/wav',
                'data' => $audioData,
                'duration' => 1.0,
                'name' => 'response_beep.wav'
            ];
        } catch (Exception $e) {
            $content[0]['text'] .= "\n\nNote: Audio generation failed: " . $e->getMessage();
        }
    }

    return ['content' => $content];
}, [
    'description' => 'Advanced echo tool with audio support',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'Message to echo'],
            'include_audio' => ['type' => 'boolean', 'description' => 'Include audio response (2025-03-26+)']
        ],
        'required' => ['message']
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'experimental' => false
    ]
]);

$toolRegistry->register('system_status', function($params, $context) {
    $includeDetails = $params['include_details'] ?? false;

    $status = [
        'status' => 'healthy',
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true),
        'timestamp' => date('c')
    ];

    if ($includeDetails) {
        $status['details'] = [
            'peak_memory' => memory_get_peak_usage(true),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'load_average' => sys_getloadavg(),
            'timezone' => date_default_timezone_get()
        ];
    }

    return $status;
}, [
    'description' => 'Get system status and health information',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'include_details' => ['type' => 'boolean', 'description' => 'Include detailed system information']
        ]
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'requiresUserConfirmation' => false
    ]
]);

// Register prompts with context awareness
$promptRegistry->register('system_prompt', function($arguments, $context) {
    $topic = $arguments['topic'] ?? 'general assistance';
    $agencyName = $context['context_data']['name'] ?? 'Unknown Agency';

    return [
        'description' => 'System prompt for AI assistance',
        'messages' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "You are an AI assistant for {$agencyName}. Focus on {$topic} and provide helpful, accurate information."
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate system prompts for AI assistants',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'Topic or domain for assistance']
        ]
    ]
]);

// Register resources with dynamic content
$resourceRegistry->register('server://metrics', function($uri, $context) {
    $metrics = [
        'server_info' => [
            'name' => 'Production MCP Server',
            'version' => '1.1.0',
            'protocol_versions' => ['2025-06-18', '2025-03-26', '2024-11-05']
        ],
        'runtime' => [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'uptime' => time() - $_SERVER['REQUEST_TIME']
        ],
        'agency' => $context['context_data']['name'] ?? 'Unknown',
        'timestamp' => date('c')
    ];

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($metrics, JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Server Metrics',
    'description' => 'Real-time server performance metrics',
    'mimeType' => 'application/json'
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
    $config,
    $logger
);

// Create OAuth server
$oauthServer = new OAuthServer(
    $storage,
    $responseFactory,
    $streamFactory,
    $config
);

// Create Slim app
$app = AppFactory::create();

// Add error middleware with detailed logging
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Add CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
        ->withHeader('Access-Control-Max-Age', '3600');
});

// Security headers middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('X-XSS-Protection', '1; mode=block')
        ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

// OAuth 2.1 endpoints with RFC 8707 Resource Indicators support
$app->get('/.well-known/oauth-authorization-server', [$mcpProvider, 'handleAuthDiscovery']);
$app->get('/.well-known/oauth-protected-resource', [$mcpProvider, 'handleResourceDiscovery']);

// OAuth flow endpoints
$app->get('/oauth/authorize', [$oauthServer, 'authorize']);
$app->post('/oauth/verify', [$oauthServer, 'verify']);
$app->post('/oauth/consent', [$oauthServer, 'consent']);
$app->post('/oauth/token', [$oauthServer, 'token']);
$app->post('/oauth/revoke', [$oauthServer, 'revoke']);
$app->post('/oauth/register', [$oauthServer, 'register']);

// Social authentication callbacks
$app->get('/oauth/google/callback', [$oauthServer, 'handleGoogleVerifyCallback']);
$app->get('/oauth/linkedin/callback', [$oauthServer, 'handleLinkedinVerifyCallback']);
$app->get('/oauth/github/callback', [$oauthServer, 'handleGithubVerifyCallback']);

// Main MCP endpoint with authentication
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]', [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

// Health check endpoint (no auth required)
$app->get('/health', function (Request $request, Response $response) use ($storage, $logger) {
    try {
        // Test database connection
        $storage->cleanup();

        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.1.0',
            'database' => 'connected',
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'supported_protocols' => ['2025-06-18', '2025-03-26', '2024-11-05']
        ];

        $logger->info('Health check passed');
    } catch (Exception $e) {
        $logger->error('Health check failed: ' . $e->getMessage());

        $health = [
            'status' => 'unhealthy',
            'timestamp' => date('c'),
            'error' => 'Database connection failed'
        ];

        $response = $response->withStatus(503);
    }

    $response->getBody()->write(json_encode($health));
    return $response->withHeader('Content-Type', 'application/json');
});

// Metrics endpoint (requires authentication)
$app->get('/metrics', function (Request $request, Response $response) use ($storage) {
    $metrics = [
        'server' => [
            'uptime' => time() - $_SERVER['REQUEST_TIME'],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ],
        'mcp' => [
            'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
            'features' => [
                'oauth_2_1' => true,
                'resource_indicators' => true,
                'tool_annotations' => true,
                'audio_content' => true,
                'elicitation' => true,
                'structured_outputs' => true
            ]
        ],
        'timestamp' => date('c')
    ];

    $response->getBody()->write(json_encode($metrics, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
})->add($mcpProvider->getAuthMiddleware());

// Welcome page with comprehensive setup information
$app->get('/', function (Request $request, Response $response) use ($baseUrl) {
    $html = "<!DOCTYPE html>
<html>
<head>
    <title>Production MCP Server</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; }
        .section { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
        .endpoint { background: #ffffff; padding: 15px; margin: 10px 0; border-radius: 6px; border: 1px solid #e9ecef; }
        code { background: #f1f3f4; padding: 3px 6px; border-radius: 4px; font-family: 'Monaco', monospace; }
        .warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
        .success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin: 20px 0; }
        .feature { background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; }
        .version-badge { display: inline-block; background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 0.8em; margin-left: 10px; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>🚀 Production MCP Server</h1>
        <p>Full-featured Model Context Protocol server with OAuth 2.1, multi-version support, and enterprise features.</p>
    </div>

    <div class='section success'>
        <h2>✅ Server Status</h2>
        <p>Your MCP server is running successfully with full protocol compliance!</p>
        <p><strong>Base URL:</strong> <code>{$baseUrl}</code></p>
        <p><strong>Supported Protocols:</strong> 2025-06-18, 2025-03-26, 2024-11-05</p>
    </div>

    <div class='section'>
        <h2>🔗 Endpoints</h2>
        <div class='endpoint'>
            <strong>MCP Endpoint:</strong> <code>/mcp/{agencyUuid}[/{sessionId}]</code><br>
            <small>Main MCP protocol endpoint (requires OAuth authentication)</small>
        </div>
        <div class='endpoint'>
            <strong>OAuth Discovery:</strong> <code>/.well-known/oauth-authorization-server</code><br>
            <small>OAuth 2.1 server metadata with RFC 8707 Resource Indicators</small>
        </div>
        <div class='endpoint'>
            <strong>Health Check:</strong> <code>/health</code><br>
            <small>Server health and status information (no auth required)</small>
        </div>
        <div class='endpoint'>
            <strong>Metrics:</strong> <code>/metrics</code><br>
            <small>Server performance metrics (requires authentication)</small>
        </div>
    </div>

    <div class='section'>
        <h2>🛠️ Available Features</h2>
        <div class='feature-grid'>
            <div class='feature'>
                <h4>🔐 OAuth 2.1 Authentication</h4>
                <p>Complete OAuth 2.1 flow with PKCE, resource indicators (RFC 8707), and social authentication via Google, LinkedIn, and GitHub.</p>
            </div>
            <div class='feature'>
                <h4>📊 Multi-Protocol Support</h4>
                <p>Automatic protocol version negotiation supporting 2024-11-05, 2025-03-26, and 2025-06-18 with feature gating.</p>
            </div>
            <div class='feature'>
                <h4>🎵 Audio Content <span class='version-badge'>2025-03-26+</span></h4>
                <p>Support for audio content in tools and prompts with comprehensive MIME type handling.</p>
            </div>
            <div class='feature'>
                <h4>📝 Structured Outputs <span class='version-badge'>2025-06-18</span></h4>
                <p>Enhanced tool responses with structured data and resource linking.</p>
            </div>
            <div class='feature'>
                <h4>🏷️ Tool Annotations <span class='version-badge'>2025-03-26+</span></h4>
                <p>Rich metadata for tools including safety hints and operational characteristics.</p>
            </div>
            <div class='feature'>
                <h4>📡 Dual Transport</h4>
                <p>Server-Sent Events (SSE) and Streamable HTTP for optimal real-time communication.</p>
            </div>
        </div>
    </div>

    <div class='section'>
        <h2>🔧 Available Tools</h2>
        <ul>
            <li><code>ping</code> - Test server connectivity</li>
            <li><code>server_info</code> - Get server information</li>
            <li><code>echo_advanced</code> - Advanced echo with audio support</li>
            <li><code>system_status</code> - System health and performance</li>
        </ul>
    </div>

    <div class='section warning'>
        <h2>⚙️ Configuration Required</h2>
        <ol>
            <li>Configure database credentials in environment variables</li>
            <li>Import database schema from <code>examples/database/database-schema.sql</code></li>
            <li>Set up OAuth clients and social authentication (optional)</li>
            <li>Configure environment variables for social providers</li>
        </ol>
    </div>

    <div class='section'>
        <h2>🧪 Quick Test</h2>
        <p>Test OAuth discovery:</p>
        <p><a href='/.well-known/oauth-authorization-server' target='_blank'>View OAuth Server Metadata</a></p>
        <p><a href='/health' target='_blank'>Check Server Health</a></p>
    </div>

    <div class='section'>
        <h2>📚 Documentation</h2>
        <p>For complete setup instructions, API documentation, and examples, visit the project repository.</p>
        <p><strong>Built with WaaSuP</strong> - Production-ready MCP server for PHP applications.</p>
    </div>
</body>
</html>";

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// Start the server
echo "🚀 Starting Production MCP Server on {$baseUrl}\n";
echo "📊 Health check: {$baseUrl}/health\n";
echo "🔐 OAuth discovery: {$baseUrl}/.well-known/oauth-authorization-server\n";
echo "📖 Documentation: {$baseUrl}/\n\n";

echo "✅ Features enabled:\n";
echo "   - OAuth 2.1 with RFC 8707 Resource Indicators\n";
echo "   - Multi-protocol support (2024-11-05, 2025-03-26, 2025-06-18)\n";
echo "   - Tool annotations and audio content\n";
echo "   - Social authentication (Google, LinkedIn, GitHub)\n";
echo "   - Dual transport (SSE + Streamable HTTP)\n\n";

echo "⚠️  Make sure to:\n";
echo "   1. Configure database credentials\n";
echo "   2. Import database schema\n";
echo "   3. Set up OAuth clients\n";
echo "   4. Configure social authentication (optional)\n\n";

$app->run();
