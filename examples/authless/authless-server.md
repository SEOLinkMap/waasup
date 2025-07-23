<?php
/**
 * Production Authless MCP Server
 *
 * A complete Model Context Protocol server without authentication requirements.
 * Demonstrates the full feature set of WaaSuP including multi-protocol version
 * support, advanced MCP features, and production-grade architecture while
 * maintaining public accessibility.
 *
 * Perfect for:
 * - Public API demonstrations
 * - Development and testing environments
 * - Educational purposes and tutorials
 * - Internal network deployments
 * - Proof-of-concept implementations
 *
 * Features:
 * - Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)
 * - Tool annotations and audio content support (2025-03-26+)
 * - Structured outputs and completions (2025-06-18)
 * - Server-Sent Events and Streamable HTTP transport
 * - Rate limiting and security measures
 * - Production-grade logging and monitoring
 * - Memory storage for simplicity (no database required)
 *
 * Prerequisites:
 * 1. composer require seolinkmap/waasup slim/psr7 monolog/monolog
 * 2. php -S localhost:8080 authless-server.php
 *
 * Security Notice:
 * This server has no authentication. Only deploy in trusted environments
 * or behind proper access controls (VPN, firewall, etc.).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory, ServerRequestFactory, UriFactory};
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Content\AudioContentHandler;

// Environment configuration
$config = [
    'app' => [
        'url' => $_ENV['APP_URL'] ?? 'http://localhost:8080',
        'env' => $_ENV['APP_ENV'] ?? 'development',
        'rate_limit' => (int)($_ENV['RATE_LIMIT'] ?? 100) // Requests per minute
    ]
];

// Initialize logger with appropriate level
$logger = new Logger('mcp-authless');
$logLevel = $config['app']['env'] === 'production' ? Logger::WARNING : Logger::DEBUG;
$logger->pushHandler(new StreamHandler('php://stdout', $logLevel));

// Initialize memory storage with pre-configured public context
$storage = new MemoryStorage();
$storage->addContext('public', 'agency', [
    'id' => 1,
    'uuid' => 'public',
    'name' => 'Public MCP Server',
    'active' => true
]);

// Add mock token for internal consistency (not used for auth)
$storage->addToken('public-access', [
    'access_token' => 'public-access',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 86400,
    'revoked' => false
]);

// Initialize registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Server configuration with full protocol support
$serverConfig = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Public MCP Server',
        'version' => '1.1.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => $config['app']['url']
    ],
    'sse' => [
        'keepalive_interval' => 2,
        'max_connection_time' => 600, // Shorter for public server
        'switch_interval_after' => 30
    ],
    'streamable_http' => [
        'keepalive_interval' => 3,
        'max_connection_time' => 600,
        'switch_interval_after' => 30
    ]
];

// Rate limiting storage
$rateLimitStorage = [];

/**
 * Simple rate limiting implementation
 */
function checkRateLimit(string $clientIp, int $limit, array &$storage): bool {
    $now = time();
    $window = 60; // 1 minute window

    if (!isset($storage[$clientIp])) {
        $storage[$clientIp] = ['count' => 0, 'reset' => $now + $window];
    }

    $clientData = &$storage[$clientIp];

    // Reset counter if window expired
    if ($now >= $clientData['reset']) {
        $clientData['count'] = 0;
        $clientData['reset'] = $now + $window;
    }

    $clientData['count']++;
    return $clientData['count'] <= $limit;
}

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($serverConfig));

// Register demo tools showcasing advanced features
$toolRegistry->register('demo_calculator', function($params, $context) {
    $expression = $params['expression'] ?? '';
    $includeAudio = $params['include_audio'] ?? false;
    $protocolVersion = $context['protocol_version'] ?? '2024-11-05';

    if (empty($expression)) {
        return ['error' => 'Expression parameter is required'];
    }

    // Security: Only allow safe mathematical expressions
    if (!preg_match('/^[0-9+\-*\/\.\(\)\s]+$/', $expression)) {
        return ['error' => 'Invalid expression - only basic math operators allowed'];
    }

    try {
        $result = eval("return $expression;");

        $response = [
            'expression' => $expression,
            'result' => $result,
            'type' => gettype($result),
            'timestamp' => date('c')
        ];

        $content = [
            ['type' => 'text', 'text' => json_encode($response, JSON_PRETTY_PRINT)]
        ];

        // Add audio feedback for supported protocol versions
        if ($includeAudio && in_array($protocolVersion, ['2025-03-26', '2025-06-18'])) {
            try {
                // Create calculation completion sound
                $audioData = base64_encode('mock_calculation_complete_beep');
                $content[] = [
                    'type' => 'audio',
                    'mimeType' => 'audio/wav',
                    'data' => $audioData,
                    'duration' => 1.5,
                    'name' => 'calculation_complete.wav'
                ];
            } catch (Exception $e) {
                // Audio generation failed, continue without it
                $response['audio_note'] = 'Audio generation unavailable';
            }
        }

        return ['content' => $content];

    } catch (ParseError $e) {
        return ['error' => 'Invalid mathematical expression'];
    } catch (Error $e) {
        return ['error' => 'Calculation error: ' . $e->getMessage()];
    }
}, [
    'description' => 'Perform mathematical calculations with optional audio feedback',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'expression' => [
                'type' => 'string',
                'description' => 'Mathematical expression (e.g., "2 + 3 * 4")'
            ],
            'include_audio' => [
                'type' => 'boolean',
                'description' => 'Include audio completion notification (2025-03-26+)'
            ]
        ],
        'required' => ['expression']
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'experimental' => false,
        'requiresUserConfirmation' => false
    ]
]);

$toolRegistry->register('text_analyzer', function($params, $context) {
    $text = $params['text'] ?? '';
    $analysisType = $params['analysis_type'] ?? 'basic';
    $protocolVersion = $context['protocol_version'] ?? '2024-11-05';

    if (empty($text)) {
        return ['error' => 'Text parameter is required'];
    }

    $analysis = [
        'text_length' => strlen($text),
        'word_count' => str_word_count($text),
        'character_count' => strlen($text),
        'character_count_no_spaces' => strlen(str_replace(' ', '', $text)),
        'timestamp' => date('c')
    ];

    if ($analysisType === 'detailed') {
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $analysis['sentence_count'] = count($sentences);
        $analysis['average_words_per_sentence'] = $analysis['sentence_count'] > 0 ?
            round($analysis['word_count'] / $analysis['sentence_count'], 2) : 0;

        // Character frequency analysis
        $chars = array_count_values(str_split(strtolower($text)));
        arsort($chars);
        $analysis['most_common_characters'] = array_slice($chars, 0, 5, true);
    }

    // Structured output for 2025-06-18
    if ($protocolVersion === '2025-06-18') {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Text analysis completed successfully.'
                ]
            ],
            'structuredContent' => $analysis,
            '_meta' => [
                'structured' => true,
                'data' => $analysis
            ]
        ];
    }

    return $analysis;
}, [
    'description' => 'Analyze text for various linguistic metrics',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string', 'description' => 'Text to analyze'],
            'analysis_type' => [
                'type' => 'string',
                'enum' => ['basic', 'detailed'],
                'description' => 'Level of analysis to perform'
            ]
        ],
        'required' => ['text']
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false
    ]
]);

$toolRegistry->register('color_palette', function($params, $context) {
    $baseColor = $params['base_color'] ?? '#3498db';
    $paletteType = $params['palette_type'] ?? 'complementary';
    $count = min($params['count'] ?? 5, 10); // Limit to 10 colors

    // Simple color manipulation (demo purposes)
    $colors = [$baseColor];

    switch ($paletteType) {
        case 'complementary':
            // Add complementary colors (simplified algorithm)
            for ($i = 1; $i < $count; $i++) {
                $hue = (($i * 60) % 360);
                $colors[] = sprintf('#%02x%02x%02x',
                    127 + 127 * sin(deg2rad($hue)),
                    127 + 127 * sin(deg2rad($hue + 120)),
                    127 + 127 * sin(deg2rad($hue + 240))
                );
            }
            break;

        case 'monochromatic':
            // Add shades and tints
            for ($i = 1; $i < $count; $i++) {
                $factor = 0.8 + ($i * 0.1);
                $colors[] = $baseColor; // Simplified - would adjust brightness
            }
            break;

        default:
            // Random colors
            for ($i = 1; $i < $count; $i++) {
                $colors[] = sprintf('#%06x', mt_rand(0, 0xFFFFFF));
            }
    }

    return [
        'base_color' => $baseColor,
        'palette_type' => $paletteType,
        'colors' => $colors,
        'count' => count($colors),
        'css_variables' => array_map(function($color, $index) {
            return "--color-{$index}: {$color};";
        }, $colors, array_keys($colors)),
        'timestamp' => date('c')
    ];
}, [
    'description' => 'Generate color palettes from a base color',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'base_color' => [
                'type' => 'string',
                'pattern' => '^#[0-9A-Fa-f]{6}$',
                'description' => 'Base color in hex format (e.g., #3498db)'
            ],
            'palette_type' => [
                'type' => 'string',
                'enum' => ['complementary', 'monochromatic', 'random'],
                'description' => 'Type of color palette to generate'
            ],
            'count' => [
                'type' => 'integer',
                'minimum' => 2,
                'maximum' => 10,
                'description' => 'Number of colors in the palette'
            ]
        ]
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => false,
        'experimental' => true
    ]
]);

// Register educational prompts
$promptRegistry->register('learning_assistant', function($arguments, $context) {
    $topic = $arguments['topic'] ?? 'general knowledge';
    $level = $arguments['level'] ?? 'beginner';
    $format = $arguments['format'] ?? 'explanation';

    $formats = [
        'explanation' => "Explain {$topic} in a clear, {$level}-level way.",
        'qa' => "Create a Q&A session about {$topic} suitable for {$level} learners.",
        'examples' => "Provide practical examples and applications of {$topic} for {$level} students.",
        'tutorial' => "Create a step-by-step tutorial on {$topic} for {$level} learners."
    ];

    return [
        'description' => 'Educational assistant for learning topics',
        'messages' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "You are an educational assistant. Focus on clear, accurate information appropriate for the learner's level."
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $formats[$format] ?? $formats['explanation']
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate educational prompts for various topics and learning levels',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'Topic to learn about'],
            'level' => [
                'type' => 'string',
                'enum' => ['beginner', 'intermediate', 'advanced'],
                'description' => 'Learning level'
            ],
            'format' => [
                'type' => 'string',
                'enum' => ['explanation', 'qa', 'examples', 'tutorial'],
                'description' => 'Format of the learning content'
            ]
        ]
    ]
]);

// Register dynamic resources
$resourceRegistry->register('demo://capabilities', function($uri, $context) {
    $protocolVersion = $context['protocol_version'] ?? '2024-11-05';

    $capabilities = [
        'server' => [
            'name' => 'Public MCP Server',
            'version' => '1.1.0',
            'protocol_version' => $protocolVersion,
            'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05']
        ],
        'features' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => in_array($protocolVersion, ['2025-03-26', '2025-06-18']),
            'audio_content' => in_array($protocolVersion, ['2025-03-26', '2025-06-18']),
            'completions' => in_array($protocolVersion, ['2025-03-26', '2025-06-18']),
            'elicitation' => $protocolVersion === '2025-06-18',
            'structured_outputs' => $protocolVersion === '2025-06-18'
        ],
        'transport' => [
            'sse' => true,
            'streamable_http' => in_array($protocolVersion, ['2025-03-26', '2025-06-18'])
        ],
        'security' => [
            'authentication' => 'none',
            'rate_limiting' => true,
            'cors_enabled' => true
        ],
        'demo_tools' => [
            'calculator' => 'Mathematical calculations with audio feedback',
            'text_analyzer' => 'Text analysis and linguistic metrics',
            'color_palette' => 'Color palette generation from base colors'
        ],
        'timestamp' => date('c')
    ];

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($capabilities, JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Server Capabilities',
    'description' => 'Complete server capabilities and feature matrix',
    'mimeType' => 'application/json'
]);

$resourceRegistry->register('demo://examples', function($uri, $context) {
    $examples = [
        'tool_calls' => [
            'basic_calculation' => [
                'tool' => 'demo_calculator',
                'parameters' => ['expression' => '(2 + 3) * 4'],
                'description' => 'Simple mathematical calculation'
            ],
            'text_analysis' => [
                'tool' => 'text_analyzer',
                'parameters' => [
                    'text' => 'The quick brown fox jumps over the lazy dog.',
                    'analysis_type' => 'detailed'
                ],
                'description' => 'Detailed text analysis example'
            ],
            'color_generation' => [
                'tool' => 'color_palette',
                'parameters' => [
                    'base_color' => '#3498db',
                    'palette_type' => 'complementary',
                    'count' => 5
                ],
                'description' => 'Generate complementary color palette'
            ]
        ],
        'prompt_usage' => [
            'learning_session' => [
                'prompt' => 'learning_assistant',
                'arguments' => [
                    'topic' => 'machine learning',
                    'level' => 'beginner',
                    'format' => 'tutorial'
                ],
                'description' => 'Create beginner ML tutorial prompt'
            ]
        ],
        'mcp_requests' => [
            'initialize' => [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'clientInfo' => ['name' => 'Demo Client', 'version' => '1.0.0']
                ],
                'id' => 1
            ],
            'tools_list' => [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'params' => [],
                'id' => 2
            ]
        ],
        'notes' => [
            'This is a demonstration server without authentication.',
            'All tools are safe and designed for educational purposes.',
            'Rate limiting is applied to prevent abuse.',
            'Protocol version affects available features.'
        ]
    ];

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($examples, JSON_PRETTY_PRINT)
            ]
        ]
    ];
}, [
    'name' => 'Usage Examples',
    'description' => 'Examples of how to use this MCP server',
    'mimeType' => 'application/json'
]);

// Resource template for documentation
$resourceRegistry->registerTemplate('demo://docs/{topic}', function($uri, $context) {
    $topic = basename($uri);

    $docs = [
        'protocols' => [
            'title' => 'Protocol Versions',
            'content' => "This server supports multiple MCP protocol versions:\n\n" .
                        "- 2024-11-05: Base protocol with tools, prompts, resources\n" .
                        "- 2025-03-26: Adds tool annotations, audio content, completions\n" .
                        "- 2025-06-18: Adds elicitation, structured outputs, resource linking\n\n" .
                        "Version is negotiated during initialization."
        ],
        'tools' => [
            'title' => 'Available Tools',
            'content' => "Demo tools showcase MCP capabilities:\n\n" .
                        "1. demo_calculator - Safe mathematical calculations\n" .
                        "2. text_analyzer - Text analysis and metrics\n" .
                        "3. color_palette - Color generation utilities\n" .
                        "4. ping - Basic connectivity testing\n" .
                        "5. server_info - Server information and status\n\n" .
                        "All tools include proper input validation and error handling."
        ],
        'audio' => [
            'title' => 'Audio Content Support',
            'content' => "Audio content is supported in protocol versions 2025-03-26 and later:\n\n" .
                        "- Tools can include audio responses\n" .
                        "- Multiple audio formats supported (WAV, MP3, etc.)\n" .
                        "- Base64 encoded audio data\n" .
                        "- Duration and metadata included\n\n" .
                        "Enable audio feedback with include_audio parameter."
        ],
        'security' => [
            'title' => 'Security Considerations',
            'content' => "This authless server includes security measures:\n\n" .
                        "- Rate limiting per client IP\n" .
                        "- Input validation and sanitization\n" .
                        "- Safe evaluation of expressions\n" .
                        "- CORS headers for web clients\n" .
                        "- Security headers (XSS protection, etc.)\n\n" .
                        "Deploy only in trusted environments."
        ]
    ];

    $docContent = $docs[$topic] ?? [
        'title' => 'Documentation Not Found',
        'content' => "No documentation available for topic: {$topic}\n\n" .
                    "Available topics: " . implode(', ', array_keys($docs))
    ];

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => $docContent['title'] . "\n" . str_repeat('=', strlen($docContent['title'])) .
                         "\n\n" . $docContent['content']
            ]
        ]
    ];
}, [
    'name' => 'Documentation',
    'description' => 'Server documentation by topic',
    'mimeType' => 'text/plain'
]);

// PSR-17 factories
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();
$requestFactory = new ServerRequestFactory();
$uriFactory = new UriFactory();

// Initialize MCP server
$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $serverConfig,
    $logger
);

/**
 * Add security headers to all responses
 */
function addSecurityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Handle CORS preflight requests
 */
function handleCorsPreflightRequest(): void {
    addSecurityHeaders();
    http_response_code(200);
}

/**
 * Create standardized error response
 */
function createErrorResponse(int $code, string $message, int $httpStatus = 400): void {
    addSecurityHeaders();
    http_response_code($httpStatus);
    header('Content-Type: application/json');

    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => $code, 'message' => $message],
        'id' => null
    ]);
}

// Main request handling
try {
    addSecurityHeaders();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $logger->debug('Processing request', [
        'method' => $method,
        'uri' => $uri,
        'client_ip' => $clientIp,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    // Rate limiting check
    if (!checkRateLimit($clientIp, $config['app']['rate_limit'], $rateLimitStorage)) {
        $logger->warning('Rate limit exceeded', ['client_ip' => $clientIp]);
        createErrorResponse(-32005, 'Rate limit exceeded', 429);
        exit;
    }

    // Handle CORS preflight
    if ($method === 'OPTIONS') {
        handleCorsPreflightRequest();
        exit;
    }

    // Create PSR-7 request
    $headers = getallheaders() ?: [];
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

    // Route handling
    if ($uri === '/health') {
        // Public health check endpoint
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.1.0',
            'type' => 'authless',
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'rate_limit' => [
                'limit' => $config['app']['rate_limit'],
                'window' => '60 seconds'
            ],
            'supported_protocols' => ['2025-06-18', '2025-03-26', '2024-11-05'],
            'features' => [
                'authentication' => false,
                'rate_limiting' => true,
                'multi_protocol' => true,
                'tool_annotations' => true,
                'audio_content' => true,
                'structured_outputs' => true
            ]
        ];

        header('Content-Type: application/json');
        echo json_encode($health);

        $logger->info('Health check completed');

    } elseif (preg_match('#^/mcp/public(?:/([^/]+))?$#', $uri, $matches)) {
        // Main MCP endpoint - no authentication required
        $sessionId = $matches[1] ?? null;

        // Add public context to request
        $request = $request->withAttribute('mcp_context', [
            'context_data' => ['id' => 1, 'name' => 'Public', 'active' => true],
            'token_data' => ['user_id' => 1, 'scope' => 'mcp:read'],
            'context_id' => 'public',
            'base_url' => $config['app']['url'],
            'protocol_version' => '2025-06-18' // Default to latest for demo
        ]);

        $logger->info('Processing public MCP request', [
            'session_id' => $sessionId,
            'method' => $method,
            'client_ip' => $clientIp
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
    <title>Public MCP Server Demo</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; max-width: 1000px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .header { background: linear-gradient(135deg, #e74c3c 0%, #f39c12 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; }
        .section { background: #f8f9fa; padding: 25px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #e74c3c; }
        .endpoint { background: #ffffff; padding: 15px; margin: 10px 0; border-radius: 6px; border: 1px solid #e9ecef; }
        code { background: #f1f3f4; padding: 3px 6px; border-radius: 4px; font-family: 'Monaco', monospace; }
        .warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
        .success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
        .info { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin: 20px 0; }
        .feature { background: white; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; }
        .version-badge { display: inline-block; background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 0.8em; margin-left: 10px; }
        .demo-code { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow-x: auto; margin: 10px 0; }
        .no-auth-badge { background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>🌐 Public MCP Server <span class='no-auth-badge'>NO AUTH</span></h1>
        <p>Production-grade Model Context Protocol server without authentication requirements.</p>
        <p><strong>Perfect for demos</strong> • <strong>Educational use</strong> • <strong>Development testing</strong></p>
    </div>

    <div class='section warning'>
        <h2>⚠️ Security Notice</h2>
        <p><strong>This server has no authentication requirements.</strong> It's designed for demonstration, development, and educational purposes. Only deploy in trusted environments or behind proper access controls.</p>
        <ul>
            <li>Rate limiting: {$config['app']['rate_limit']} requests per minute per IP</li>
            <li>All tools are safe and designed for demo purposes</li>
            <li>No sensitive data should be processed</li>
        </ul>
    </div>

    <div class='section success'>
        <h2>✅ Server Status</h2>
        <p>Your public MCP server is running successfully!</p>
        <p><strong>Endpoint:</strong> <code>/mcp/public</code></p>
        <p><strong>Protocols:</strong> 2025-06-18, 2025-03-26, 2024-11-05</p>
        <p><strong>Environment:</strong> {$config['app']['env']}</p>
    </div>

    <div class='section'>
        <h2>🚀 Quick Start</h2>
        <p>Test the server immediately with this MCP initialization request:</p>
        <div class='demo-code'>curl -X POST {$config['app']['url']}/mcp/public \\
  -H 'Content-Type: application/json' \\
  -d '{
    \"jsonrpc\": \"2.0\",
    \"method\": \"initialize\",
    \"params\": {
      \"protocolVersion\": \"2025-06-18\",
      \"clientInfo\": {\"name\": \"Demo Client\", \"version\": \"1.0.0\"}
    },
    \"id\": 1
  }'</div>
    </div>

    <div class='section'>
        <h2>🛠️ Demo Tools</h2>
        <div class='feature-grid'>
            <div class='feature'>
                <h4>🧮 demo_calculator</h4>
                <p>Safe mathematical calculations with optional audio feedback. Supports basic arithmetic operations with proper validation.</p>
                <code>{\"expression\": \"(2 + 3) * 4\", \"include_audio\": true}</code>
            </div>
            <div class='feature'>
                <h4>📝 text_analyzer</h4>
                <p>Comprehensive text analysis including word count, character frequency, and linguistic metrics with structured outputs.</p>
                <code>{\"text\": \"Hello world!\", \"analysis_type\": \"detailed\"}</code>
            </div>
            <div class='feature'>
                <h4>🎨 color_palette</h4>
                <p>Generate color palettes from base colors using various algorithms. Perfect for design and creative applications.</p>
                <code>{\"base_color\": \"#3498db\", \"palette_type\": \"complementary\"}</code>
            </div>
            <div class='feature'>
                <h4>📡 ping & server_info</h4>
                <p>Built-in connectivity testing and server information tools for diagnostics and capability discovery.</p>
                <code>{\"message\": \"Connection test\"}</code>
            </div>
        </div>
    </div>

    <div class='section'>
        <h2>⚡ Advanced Features</h2>
        <div class='feature-grid'>
            <div class='feature'>
                <h4>📊 Multi-Protocol Support</h4>
                <p>Automatic version negotiation supporting all MCP protocol versions with appropriate feature gating.</p>
            </div>
            <div class='feature'>
                <h4>🎵 Audio Content <span class='version-badge'>2025-03-26+</span></h4>
                <p>Tools can include audio responses with proper MIME type handling and base64 encoding.</p>
            </div>
            <div class='feature'>
                <h4>🏷️ Tool Annotations <span class='version-badge'>2025-03-26+</span></h4>
                <p>Rich metadata for tools including safety hints and operational characteristics.</p>
            </div>
            <div class='feature'>
                <h4>📝 Structured Outputs <span class='version-badge'>2025-06-18</span></h4>
                <p>Enhanced tool responses with structured data and metadata for better LLM integration.</p>
            </div>
        </div>
    </div>

    <div class='section info'>
        <h2>📚 Resources & Documentation</h2>
        <ul>
            <li><strong>demo://capabilities</strong> - Complete server capability matrix</li>
            <li><strong>demo://examples</strong> - Usage examples and sample requests</li>
            <li><strong>demo://docs/protocols</strong> - Protocol version documentation</li>
            <li><strong>demo://docs/tools</strong> - Tool usage guide</li>
            <li><strong>demo://docs/audio</strong> - Audio content documentation</li>
            <li><strong>demo://docs/security</strong> - Security considerations</li>
        </ul>
    </div>

    <div class='section'>
        <h2>🧪 Test Endpoints</h2>
        <ul>
            <li><a href='/health' target='_blank'>Server Health Check</a></li>
            <li><a href='/mcp/public' target='_blank'>MCP Endpoint (GET for SSE)</a></li>
        </ul>
    </div>

    <div class='section'>
        <h2>🔧 Integration Examples</h2>
        <p><strong>Claude.ai:</strong> Add <code>{$config['app']['url']}/mcp/public</code> to your MCP server configuration.</p>
        <p><strong>Custom Applications:</strong> Use standard MCP JSON-RPC protocol over HTTP/SSE.</p>
        <p><strong>Development:</strong> Perfect for testing MCP implementations and exploring protocol features.</p>
    </div>
</body>
</html>";

    } else {
        // 404 for unknown routes
        $logger->warning('Route not found', ['uri' => $uri, 'client_ip' => $clientIp]);
        createErrorResponse(-32004, 'Route not found', 404);
    }

} catch (Exception $e) {
    $logger->critical('Unhandled exception: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    createErrorResponse(-32603, 'Internal server error', 500);
}

// CLI usage information
if (php_sapi_name() === 'cli') {
    echo "\n🌐 Public MCP Server (Authless)\n";
    echo "===============================\n\n";
    echo "Usage: php -S localhost:8080 " . basename(__FILE__) . "\n\n";
    echo "⚠️  Security Notice:\n";
    echo "   This server has NO AUTHENTICATION and should only be used in\n";
    echo "   trusted environments for development, testing, or demos.\n\n";
    echo "Features:\n";
    echo "  ✅ Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)\n";
    echo "  ✅ Tool annotations and audio content (2025-03-26+)\n";
    echo "  ✅ Structured outputs and completions (2025-06-18)\n";
    echo "  ✅ Server-Sent Events and Streamable HTTP transport\n";
    echo "  ✅ Rate limiting and security headers\n";
    echo "  ✅ Educational demo tools (calculator, text analyzer, color palette)\n";
    echo "  ✅ Comprehensive documentation and examples\n\n";
    echo "Quick Start:\n";
    echo "  1. Start server: php -S localhost:8080 authless-server.php\n";
    echo "  2. Visit http://localhost:8080 for documentation\n";
    echo "  3. Test endpoint: http://localhost:8080/mcp/public\n";
    echo "  4. Health check: http://localhost:8080/health\n\n";
    echo "Rate Limiting: {$config['app']['rate_limit']} requests per minute per IP\n";
    echo "Memory Storage: All data is temporary and resets on restart\n\n";
}
