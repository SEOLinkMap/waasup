<?php
/**
 * Production Standalone MCP SaaS Server
 *
 * A complete Model Context Protocol server implementation without frameworks.
 * Demonstrates the full feature set of WaaSuP including OAuth 2.1 with RFC 8707
 * Resource Indicators, multi-protocol version support, and advanced MCP features.
 *
 * Features:
 * - OAuth 2.1 with RFC 8707 Resource Indicators (MCP 2025-06-18)
 * - Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)
 * - Social authentication (Google, LinkedIn, GitHub)
 * - Tool annotations and audio content support (2025-03-26+)
 * - Structured outputs and elicitation (2025-06-18)
 * - Server-Sent Events and Streamable HTTP transport
 * - Production-grade security and error handling
 *
 * Prerequisites:
 * 1. composer require seolinkmap/waasup slim/psr7 monolog/monolog
 * 2. Set up database and import schema from examples/database/database-schema.sql
 * 3. Configure environment variables
 * 4. php -S localhost:8080 standalone-server.php
 *
 * Environment Variables:
 * - DB_HOST, DB_NAME, DB_USER, DB_PASS: Database connection
 * - APP_URL: Base URL for OAuth redirects
 * - GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET: Google OAuth (optional)
 * - LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET: LinkedIn OAuth (optional)
 * - GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET: GitHub OAuth (optional)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory, ServerRequestFactory, UriFactory};
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Auth\{OAuthServer, Middleware\AuthMiddleware};
use Seolinkmap\Waasup\Discovery\WellKnownProvider;
use Seolinkmap\Waasup\Content\AudioContentHandler;

// Environment configuration with secure defaults
$config = [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'mcp_server',
        'user' => $_ENV['DB_USER'] ?? 'mcp_user',
        'pass' => $_ENV['DB_PASS'] ?? 'secure_password'
    ],
    'app' => [
        'url' => $_ENV['APP_URL'] ?? 'http://localhost:8080',
        'env' => $_ENV['APP_ENV'] ?? 'production'
    ],
    'social' => [
        'google' => [
            'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
            'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? ''
        ],
        'linkedin' => [
            'client_id' => $_ENV['LINKEDIN_CLIENT_ID'] ?? '',
            'client_secret' => $_ENV['LINKEDIN_CLIENT_SECRET'] ?? ''
        ],
        'github' => [
            'client_id' => $_ENV['GITHUB_CLIENT_ID'] ?? '',
            'client_secret' => $_ENV['GITHUB_CLIENT_SECRET'] ?? ''
        ]
    ]
];

// Initialize logger with appropriate level
$logger = new Logger('mcp-standalone');
$logLevel = $config['app']['env'] === 'development' ? Logger::DEBUG : Logger::INFO;
$logger->pushHandler(new StreamHandler('php://stdout', $logLevel));

// Database connection with comprehensive error handling
try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5
    ]);

    $logger->info('Database connection established');
} catch (PDOException $e) {
    $logger->critical('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Service temporarily unavailable']);
    exit(1);
}

// Initialize storage and registries
$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration with full protocol support
$serverConfig = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Standalone MCP Server',
        'version' => '1.1.0'
    ],
    'auth' => [
        'context_types' => ['agency', 'user'],
        'base_url' => $config['app']['url'],
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
    ]
];

// Add social authentication configuration if available
foreach (['google', 'linkedin', 'github'] as $provider) {
    if (!empty($config['social'][$provider]['client_id'])) {
        $serverConfig[$provider] = [
            'client_id' => $config['social'][$provider]['client_id'],
            'client_secret' => $config['social'][$provider]['client_secret'],
            'redirect_uri' => $config['app']['url'] . "/oauth/{$provider}/callback"
        ];
    }
}

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($serverConfig));

// Register advanced tools with protocol version awareness
$toolRegistry->register('system_diagnostics', function($params, $context) {
    $includeDetails = $params['include_details'] ?? false;
    $includeAudio = $params['include_audio'] ?? false;
    $protocolVersion = $context['protocol_version'] ?? '2024-11-05';

    $diagnostics = [
        'system' => [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'uptime' => time() - $_SERVER['REQUEST_TIME']
        ],
        'server' => [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Built-in PHP Server',
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null
        ],
        'mcp' => [
            'protocol_version' => $protocolVersion,
            'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
            'features_enabled' => [
                'oauth_2_1' => true,
                'resource_indicators' => $protocolVersion === '2025-06-18',
                'tool_annotations' => in_array($protocolVersion, ['2025-03-26', '2025-06-18']),
                'audio_content' => in_array($protocolVersion, ['2025-03-26', '2025-06-18']),
                'structured_outputs' => $protocolVersion === '2025-06-18'
            ]
        ],
        'timestamp' => date('c')
    ];

    if ($includeDetails) {
        $diagnostics['details'] = [
            'extensions' => get_loaded_extensions(),
            'ini_settings' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize')
            ],
            'environment' => [
                'os' => PHP_OS,
                'sapi' => PHP_SAPI,
                'timezone' => date_default_timezone_get()
            ]
        ];
    }

    $content = [
        ['type' => 'text', 'text' => json_encode($diagnostics, JSON_PRETTY_PRINT)]
    ];

    // Add audio notification for protocol versions that support it
    if ($includeAudio && in_array($protocolVersion, ['2025-03-26', '2025-06-18'])) {
        try {
            // Create diagnostic completion sound
            $audioData = base64_encode('mock_diagnostic_complete_audio');
            $content[] = [
                'type' => 'audio',
                'mimeType' => 'audio/wav',
                'data' => $audioData,
                'duration' => 2.0,
                'name' => 'diagnostic_complete.wav'
            ];
        } catch (Exception $e) {
            $diagnostics['audio_error'] = $e->getMessage();
        }
    }

    return ['content' => $content];
}, [
    'description' => 'Comprehensive system diagnostics with audio feedback',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'include_details' => [
                'type' => 'boolean',
                'description' => 'Include detailed system information'
            ],
            'include_audio' => [
                'type' => 'boolean',
                'description' => 'Include audio completion notification (2025-03-26+)'
            ]
        ]
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'requiresUserConfirmation' => false,
        'experimental' => false
    ]
]);

$toolRegistry->register('database_status', function($params, $context) use ($pdo) {
    $includeQueries = $params['include_queries'] ?? false;

    try {
        // Test basic connectivity
        $pdo->query('SELECT 1');

        $status = [
            'connection' => 'healthy',
            'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_INFO),
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
            'timestamp' => date('c')
        ];

        if ($includeQueries) {
            // Get table information
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $status['tables'] = [];

            foreach ($tables as $table) {
                if (strpos($table, 'mcp_') === 0) {
                    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    $status['tables'][$table] = ['row_count' => (int)$count];
                }
            }
        }

        return $status;

    } catch (PDOException $e) {
        return [
            'connection' => 'failed',
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ];
    }
}, [
    'description' => 'Check database connection status and health',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'include_queries' => [
                'type' => 'boolean',
                'description' => 'Include table statistics (requires additional queries)'
            ]
        ]
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'requiresUserConfirmation' => false
    ]
]);

// Register context-aware prompts
$promptRegistry->register('troubleshooting_assistant', function($arguments, $context) {
    $issue = $arguments['issue'] ?? 'general troubleshooting';
    $severity = $arguments['severity'] ?? 'medium';
    $agencyName = $context['context_data']['name'] ?? 'Unknown Agency';

    return [
        'description' => 'Troubleshooting assistant for MCP server issues',
        'messages' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "You are a technical support specialist for {$agencyName}'s MCP server. Help troubleshoot issues with a focus on practical solutions."
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "I'm experiencing a {$severity} severity issue: {$issue}. What steps should I take to diagnose and resolve this?"
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate troubleshooting guidance for MCP server issues',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'issue' => ['type' => 'string', 'description' => 'Description of the issue'],
            'severity' => [
                'type' => 'string',
                'enum' => ['low', 'medium', 'high', 'critical'],
                'description' => 'Issue severity level'
            ]
        ]
    ]
]);

// Register dynamic resources
$resourceRegistry->register('server://health', function($uri, $context) use ($pdo) {
    try {
        // Test database
        $pdo->query('SELECT 1');
        $dbStatus = 'healthy';
    } catch (Exception $e) {
        $dbStatus = 'unhealthy';
    }

    $health = [
        'status' => $dbStatus === 'healthy' ? 'healthy' : 'unhealthy',
        'components' => [
            'database' => ['status' => $dbStatus],
            'memory' => [
                'status' => memory_get_usage(true) < (1024 * 1024 * 512) ? 'healthy' : 'warning',
                'usage_bytes' => memory_get_usage(true)
            ],
            'disk' => [
                'status' => disk_free_space('.') > (1024 * 1024 * 100) ? 'healthy' : 'warning',
                'free_bytes' => disk_free_space('.')
            ]
        ],
        'server_info' => [
            'php_version' => PHP_VERSION,
            'uptime_seconds' => time() - $_SERVER['REQUEST_TIME']
        ],
        'agency' => $context['context_data']['name'] ?? 'Unknown',
        'timestamp' => date('c')
    ];

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($health, JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Server Health Status',
    'description' => 'Comprehensive server health monitoring data',
    'mimeType' => 'application/json'
]);

// PSR-17 factories
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();
$requestFactory = new ServerRequestFactory();
$uriFactory = new UriFactory();

// Initialize core services
$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $serverConfig,
    $logger
);

$authMiddleware = new AuthMiddleware(
    $storage,
    $responseFactory,
    $streamFactory,
    $serverConfig['auth']
);

$oauthServer = new OAuthServer(
    $storage,
    $responseFactory,
    $streamFactory,
    $serverConfig
);

$discoveryProvider = new WellKnownProvider($serverConfig['discovery']);

/**
 * Enhanced authentication helper with comprehensive validation
 */
function authenticateRequest(array $headers, DatabaseStorage $storage, array $config): ?array {
    $authHeader = '';
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $authHeader = is_array($value) ? $value[0] : $value;
            break;
        }
    }

    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return null;
    }

    $token = $matches[1];
    $tokenData = $storage->validateToken($token);

    if (!$tokenData) {
        return null;
    }

    // Get context data based on token
    $contextData = null;
    if (isset($tokenData['agency_id'])) {
        $contextData = $storage->getContextData(
            // Find agency UUID by ID (simplified for example)
            '550e8400-e29b-41d4-a716-446655440000',
            'agency'
        );
    }

    if (!$contextData) {
        return null;
    }

    return [
        'context_data' => $contextData,
        'token_data' => $tokenData,
        'context_id' => '550e8400-e29b-41d4-a716-446655440000',
        'base_url' => $config['auth']['base_url'],
        'protocol_version' => '2025-06-18' // Default to latest
    ];
}

/**
 * Create standardized error response
 */
function createErrorResponse(int $code, string $message, int $httpStatus = 400): void {
    http_response_code($httpStatus);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => $code, 'message' => $message],
        'id' => null
    ]);
}

/**
 * Handle CORS preflight requests
 */
function handleCorsPreflightRequest(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
    header('Access-Control-Max-Age: 3600');
    http_response_code(200);
}

/**
 * Add security headers to all responses
 */
function addSecurityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

// Main request handling
try {
    addSecurityHeaders();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $headers = getallheaders() ?: [];

    $logger->debug('Processing request', [
        'method' => $method,
        'uri' => $uri,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    // Handle CORS preflight
    if ($method === 'OPTIONS') {
        handleCorsPreflightRequest();
        exit;
    }

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

    $response = $responseFactory->createResponse();

    // Route handling with comprehensive endpoint support
    if ($uri === '/health') {
        // Public health check endpoint
        try {
            $storage->cleanup(); // Test database
            $health = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'version' => '1.1.0',
                'php_version' => PHP_VERSION,
                'database' => 'connected',
                'memory_usage' => memory_get_usage(true),
                'supported_protocols' => ['2025-06-18', '2025-03-26', '2024-11-05'],
                'features' => [
                    'oauth_2_1' => true,
                    'resource_indicators' => true,
                    'social_auth' => !empty($serverConfig['google']['client_id']) ||
                                   !empty($serverConfig['linkedin']['client_id']) ||
                                   !empty($serverConfig['github']['client_id']),
                    'tool_annotations' => true,
                    'audio_content' => true,
                    'structured_outputs' => true
                ]
            ];

            $logger->info('Health check passed');
        } catch (Exception $e) {
            $logger->error('Health check failed: ' . $e->getMessage());
            $health = [
                'status' => 'unhealthy',
                'timestamp' => date('c'),
                'error' => 'Service degraded'
            ];
            http_response_code(503);
        }

        header('Content-Type: application/json');
        echo json_encode($health);

    } elseif ($uri === '/.well-known/oauth-authorization-server') {
        // OAuth authorization server discovery
        $response = $discoveryProvider->authorizationServer($request, $response);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $response->getBody();

    } elseif ($uri === '/.well-known/oauth-protected-resource') {
        // OAuth protected resource discovery
        $response = $discoveryProvider->protectedResource($request, $response);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $response->getBody();

    } elseif (preg_match('#^/oauth/(authorize|verify|consent|token|revoke|register)$#', $uri, $matches)) {
        // OAuth flow endpoints
        $endpoint = $matches[1];
        $response = $oauthServer->$endpoint($request, $response);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $response->getBody();

    } elseif (preg_match('#^/oauth/(google|linkedin|github)/callback$#', $uri, $matches)) {
        // Social authentication callbacks
        $provider = $matches[1];
        $methodName = 'handle' . ucfirst($provider) . 'VerifyCallback';
        $response = $oauthServer->$methodName($request, $response);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $response->getBody();

    } elseif (preg_match('#^/mcp/([^/]+)(?:/([^/]+))?$#', $uri, $matches)) {
        // Main MCP endpoint with authentication
        $agencyUuid = $matches[1];
        $sessionId = $matches[2] ?? null;

        $authContext = authenticateRequest($headers, $storage, $serverConfig);

        if (!$authContext) {
            $logger->warning('Authentication failed for MCP endpoint');
            createErrorResponse(-32000, 'Authentication required', 401);
            exit;
        }

        // Add authentication context to request
        $request = $request->withAttribute('mcp_context', $authContext);

        $logger->info('Processing authenticated MCP request', [
            'agency_uuid' => $agencyUuid,
            'session_id' => $sessionId,
            'method' => $method
        ]);

        // Handle with MCP server
        $response = $mcpServer->handle($request, $response);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $response->getBody();

    } elseif ($uri === '/') {
        // Welcome page with comprehensive documentation
        header('Content-Type: text/html');
        echo "<!DOCTYPE html>
<html>
<head>
    <title>Standalone MCP Server</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; max-width: 1000px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; }
        .section { background: #f8f9fa; padding: 25px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
        .endpoint { background: #ffffff; padding: 15px; margin: 10px 0; border-radius: 6px; border: 1px solid #e9ecef; }
        code { background: #f1f3f4; padding: 3px 6px; border-radius: 4px; font-family: 'Monaco', monospace; }
        .success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin: 20px 0; }
        .feature { background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; }
        .version-badge { display: inline-block; background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 0.8em; margin-left: 10px; }
        .status-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .status-healthy { background: #28a745; }
        .status-warning { background: #ffc107; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>🚀 Standalone MCP Server</h1>
        <p>Production-ready Model Context Protocol server with OAuth 2.1, multi-protocol support, and enterprise features.</p>
        <p><strong>Framework-free</strong> • <strong>PSR-compliant</strong> • <strong>Database-backed</strong></p>
    </div>

    <div class='section success'>
        <h2><span class='status-indicator status-healthy'></span>Server Status</h2>
        <p>Your standalone MCP server is running successfully!</p>
        <p><strong>Base URL:</strong> <code>{$config['app']['url']}</code></p>
        <p><strong>Environment:</strong> {$config['app']['env']}</p>
        <p><strong>Protocols:</strong> 2025-06-18, 2025-03-26, 2024-11-05</p>
    </div>

    <div class='section'>
        <h2>🔗 API Endpoints</h2>
        <div class='endpoint'>
            <strong>MCP Protocol:</strong> <code>/mcp/{agencyUuid}[/{sessionId}]</code><br>
            <small>Main MCP endpoint supporting GET (SSE/Streamable HTTP) and POST (JSON-RPC)</small>
        </div>
        <div class='endpoint'>
            <strong>OAuth Discovery:</strong> <code>/.well-known/oauth-authorization-server</code><br>
            <small>RFC 8414 OAuth 2.1 Authorization Server Metadata</small>
        </div>
        <div class='endpoint'>
            <strong>Resource Discovery:</strong> <code>/.well-known/oauth-protected-resource</code><br>
            <small>RFC 9728 OAuth Protected Resource Metadata</small>
        </div>
        <div class='endpoint'>
            <strong>Health Check:</strong> <code>/health</code><br>
            <small>Server health status and capability information</small>
        </div>
    </div>

    <div class='section'>
        <h2>⚡ Advanced Features</h2>
        <div class='feature-grid'>
            <div class='feature'>
                <h4>🔐 OAuth 2.1 + RFC 8707</h4>
                <p>Complete OAuth 2.1 implementation with Resource Indicators (RFC 8707) for MCP 2025-06-18. Supports PKCE, social authentication, and token binding.</p>
            </div>
            <div class='feature'>
                <h4>🎵 Audio Content <span class='version-badge'>2025-03-26+</span></h4>
                <p>Native audio content support in tools and prompts with comprehensive MIME type handling and base64 encoding.</p>
            </div>
            <div class='feature'>
                <h4>📊 Multi-Protocol</h4>
                <p>Automatic version negotiation with feature gating. Supports 2024-11-05, 2025-03-26, and 2025-06-18 protocol versions.</p>
            </div>
            <div class='feature'>
                <h4>🏷️ Tool Annotations <span class='version-badge'>2025-03-26+</span></h4>
                <p>Rich metadata for tools including safety hints, confirmation requirements, and operational characteristics.</p>
            </div>
            <div class='feature'>
                <h4>📝 Structured Outputs <span class='version-badge'>2025-06-18</span></h4>
                <p>Enhanced tool responses with structured data and resource linking for better LLM integration.</p>
            </div>
            <div class='feature'>
                <h4>📡 Dual Transport</h4>
                <p>Server-Sent Events for 2024-11-05 and Streamable HTTP for 2025-03-26+ with automatic selection.</p>
            </div>
        </div>
    </div>

    <div class='section'>
        <h2>🛠️ Available Tools</h2>
        <ul>
            <li><code>ping</code> - Test server connectivity and response times</li>
            <li><code>server_info</code> - Comprehensive server information and capabilities</li>
            <li><code>system_diagnostics</code> - System health with optional audio feedback</li>
            <li><code>database_status</code> - Database connectivity and table statistics</li>
        </ul>
    </div>

    <div class='section warning'>
        <h2>⚙️ Configuration</h2>
        <p>This server requires proper configuration:</p>
        <ol>
            <li>Database connection (MySQL/PostgreSQL)</li>
            <li>OAuth client registration</li>
            <li>Social authentication setup (optional)</li>
            <li>SSL/TLS certificate for production</li>
        </ol>
    </div>

    <div class='section'>
        <h2>🧪 Quick Tests</h2>
        <ul>
            <li><a href='/health' target='_blank'>Server Health Check</a></li>
            <li><a href='/.well-known/oauth-authorization-server' target='_blank'>OAuth Server Metadata</a></li>
            <li><a href='/.well-known/oauth-protected-resource' target='_blank'>Resource Server Metadata</a></li>
        </ul>
    </div>

    <div class='section'>
        <h2>📚 Integration</h2>
        <p>Use this MCP server with:</p>
        <ul>
            <li><strong>Claude.ai</strong> - Add server URL to Claude's MCP configuration</li>
            <li><strong>Custom LLM Applications</strong> - Use OAuth 2.1 flow for authentication</li>
            <li><strong>Development Tools</strong> - Integrate with IDE extensions and CLI tools</li>
        </ul>
    </div>
</body>
</html>";

    } else {
        // 404 for unknown routes
        $logger->warning('Route not found', ['uri' => $uri]);
        createErrorResponse(-32004, 'Route not found', 404);
    }

} catch (Exception $e) {
    $logger->critical('Unhandled exception: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);

    createErrorResponse(-32603, 'Internal server error', 500);
}

// CLI usage information
if (php_sapi_name() === 'cli') {
    echo "\n🚀 Standalone MCP Server\n";
    echo "=======================\n\n";
    echo "Usage: php -S localhost:8080 " . basename(__FILE__) . "\n\n";
    echo "Features:\n";
    echo "  ✅ OAuth 2.1 with RFC 8707 Resource Indicators\n";
    echo "  ✅ Multi-protocol version support\n";
    echo "  ✅ Social authentication (Google, LinkedIn, GitHub)\n";
    echo "  ✅ Tool annotations and audio content\n";
    echo "  ✅ Structured outputs and elicitation\n";
    echo "  ✅ Production-grade security and logging\n\n";
    echo "Environment Variables Required:\n";
    echo "  DB_HOST, DB_NAME, DB_USER, DB_PASS\n";
    echo "  APP_URL (for OAuth redirects)\n";
    echo "  Social auth credentials (optional)\n\n";
    echo "Quick Start:\n";
    echo "  1. Configure database connection\n";
    echo "  2. Import database schema\n";
    echo "  3. Set environment variables\n";
    echo "  4. Start server: php -S localhost:8080 standalone-server.php\n";
    echo "  5. Visit http://localhost:8080 for documentation\n\n";
}
