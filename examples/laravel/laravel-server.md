<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Content\AudioContentHandler;

/**
 * Production Laravel MCP Server Controller
 *
 * Features:
 * - OAuth 2.1 with RFC 8707 Resource Indicators (MCP 2025-06-18)
 * - Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)
 * - Social authentication (Google, LinkedIn, GitHub)
 * - Tool annotations and audio content support
 * - Laravel-specific integrations (Eloquent, Cache, Queue, etc.)
 * - Server-Sent Events and Streamable HTTP transport
 *
 * Prerequisites:
 * 1. composer require seolinkmap/waasup nyholm/psr7 symfony/psr-http-message-bridge
 * 2. Add service provider to config/app.php
 * 3. Set up database and import schema from examples/database/database-schema.sql
 * 4. Configure database connection in .env
 * 5. Add routes to routes/web.php or routes/api.php
 * 6. Configure social authentication in .env
 *
 * Routes (routes/web.php):
 * Route::get('/.well-known/oauth-authorization-server', [MCPController::class, 'authDiscovery']);
 * Route::get('/.well-known/oauth-protected-resource', [MCPController::class, 'resourceDiscovery']);
 * Route::get('/oauth/authorize', [MCPController::class, 'oauthAuthorize']);
 * Route::post('/oauth/verify', [MCPController::class, 'oauthVerify']);
 * Route::post('/oauth/consent', [MCPController::class, 'oauthConsent']);
 * Route::post('/oauth/token', [MCPController::class, 'oauthToken']);
 * Route::post('/oauth/revoke', [MCPController::class, 'oauthRevoke']);
 * Route::post('/oauth/register', [MCPController::class, 'oauthRegister']);
 * Route::get('/oauth/google/callback', [MCPController::class, 'googleCallback']);
 * Route::get('/oauth/linkedin/callback', [MCPController::class, 'linkedinCallback']);
 * Route::get('/oauth/github/callback', [MCPController::class, 'githubCallback']);
 * Route::match(['GET', 'POST'], '/mcp/{agencyUuid}/{sessID?}', [MCPController::class, 'handle'])
 *     ->middleware('mcp.auth');
 * Route::get('/mcp-health', [MCPController::class, 'health']);
 * Route::get('/mcp-metrics', [MCPController::class, 'metrics'])->middleware('mcp.auth');
 */
class MCPController extends Controller
{
    private LaravelMCPProvider $mcpProvider;
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private OAuthServer $oauthServer;

    public function __construct(
        LaravelMCPProvider $mcpProvider,
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        OAuthServer $oauthServer
    ) {
        $this->mcpProvider = $mcpProvider;
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->resourceRegistry = $resourceRegistry;
        $this->oauthServer = $oauthServer;

        // Register Laravel-specific features
        $this->registerLaravelFeatures();
    }

    /**
     * OAuth authorization server discovery
     */
    public function authDiscovery(Request $request): Response
    {
        return $this->mcpProvider->handleAuthDiscovery($request);
    }

    /**
     * OAuth protected resource discovery
     */
    public function resourceDiscovery(Request $request): Response
    {
        return $this->mcpProvider->handleResourceDiscovery($request);
    }

    /**
     * OAuth authorization endpoint
     */
    public function oauthAuthorize(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->authorize($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * OAuth verification endpoint
     */
    public function oauthVerify(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->verify($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * OAuth consent endpoint
     */
    public function oauthConsent(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->consent($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * OAuth token endpoint
     */
    public function oauthToken(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->token($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * OAuth token revocation endpoint
     */
    public function oauthRevoke(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->revoke($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * OAuth client registration endpoint
     */
    public function oauthRegister(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->register($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * Google OAuth callback
     */
    public function googleCallback(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->handleGoogleVerifyCallback($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * LinkedIn OAuth callback
     */
    public function linkedinCallback(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->handleLinkedinVerifyCallback($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * GitHub OAuth callback
     */
    public function githubCallback(Request $request): Response
    {
        $psrRequest = $this->convertToPsrRequest($request);
        $psrResponse = $this->createPsrResponse();

        $result = $this->oauthServer->handleGithubVerifyCallback($psrRequest, $psrResponse);
        return $this->convertToLaravelResponse($result);
    }

    /**
     * Handle MCP requests (requires authentication via middleware)
     */
    public function handle(Request $request, string $agencyUuid, ?string $sessID = null): Response
    {
        return $this->mcpProvider->handleMCP($request);
    }

    /**
     * Health check endpoint (no authentication required)
     */
    public function health(Request $request): Response
    {
        try {
            // Test database connection
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Exception $e) {
            $dbStatus = 'disconnected';
            Log::error('Database health check failed: ' . $e->getMessage());
        }

        $health = [
            'status' => $dbStatus === 'connected' ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.1.0',
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'database' => $dbStatus,
            'environment' => config('app.env'),
            'memory_usage' => memory_get_usage(true),
            'mcp_features' => [
                'oauth_2_1' => true,
                'resource_indicators' => true,
                'multi_protocol' => true,
                'social_auth' => true,
                'tool_annotations' => true,
                'audio_content' => true,
                'structured_outputs' => true
            ],
            'supported_protocols' => ['2025-06-18', '2025-03-26', '2024-11-05']
        ];

        return response()->json($health, $dbStatus === 'connected' ? 200 : 503);
    }

    /**
     * Metrics endpoint (requires authentication)
     */
    public function metrics(Request $request): Response
    {
        $metrics = [
            'laravel' => [
                'version' => app()->version(),
                'environment' => config('app.env'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale')
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'load_average' => sys_getloadavg()
            ],
            'cache' => [
                'default_driver' => config('cache.default'),
                'can_cache' => Cache::has('test') || Cache::put('test', true, 1)
            ],
            'database' => [
                'default_connection' => config('database.default'),
                'status' => $this->checkDatabaseConnection()
            ],
            'queue' => [
                'default_connection' => config('queue.default'),
                'failed_jobs' => DB::table('failed_jobs')->count()
            ],
            'mcp' => [
                'active_sessions' => $this->getActiveSessionCount(),
                'protocol_versions' => ['2025-06-18', '2025-03-26', '2024-11-05']
            ],
            'timestamp' => now()->toISOString()
        ];

        return response()->json($metrics);
    }

    /**
     * Register Laravel-specific tools, prompts, and resources
     */
    private function registerLaravelFeatures(): void
    {
        $this->registerLaravelTools();
        $this->registerLaravelPrompts();
        $this->registerLaravelResources();
    }

    /**
     * Register Laravel-specific tools
     */
    private function registerLaravelTools(): void
    {
        // Enhanced user statistics tool
        $this->toolRegistry->register('laravel_user_stats', function($params, $context) {
            $includeDetails = $params['include_details'] ?? false;

            $stats = [
                'total_users' => \App\Models\User::count(),
                'active_users' => \App\Models\User::whereNotNull('email_verified_at')->count(),
                'recent_users' => \App\Models\User::where('created_at', '>=', now()->subDays(7))->count(),
                'agency' => $context['context_data']['name'] ?? 'Unknown',
                'timestamp' => now()->toISOString()
            ];

            if ($includeDetails) {
                $stats['details'] = [
                    'users_by_month' => \App\Models\User::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
                        ->groupBy('month')
                        ->orderBy('month', 'desc')
                        ->limit(12)
                        ->get()
                        ->toArray(),
                    'verification_rate' => round(
                        (\App\Models\User::whereNotNull('email_verified_at')->count() / max(\App\Models\User::count(), 1)) * 100, 2
                    )
                ];
            }

            return $stats;
        }, [
            'description' => 'Get comprehensive user statistics from Laravel application',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'include_details' => ['type' => 'boolean', 'description' => 'Include detailed analytics']
                ]
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'requiresUserConfirmation' => false
            ]
        ]);

        // Application management tool with audio notifications
        $this->toolRegistry->register('laravel_app_manager', function($params, $context) {
            $action = $params['action'] ?? 'info';
            $includeAudio = $params['include_audio'] ?? false;
            $protocolVersion = $context['protocol_version'] ?? '2024-11-05';

            $result = ['action' => $action, 'timestamp' => now()->toISOString()];

            switch ($action) {
                case 'cache_clear':
                    Artisan::call('cache:clear');
                    $result['message'] = 'Application cache cleared successfully';
                    $result['output'] = Artisan::output();
                    break;

                case 'config_cache':
                    Artisan::call('config:cache');
                    $result['message'] = 'Configuration cached successfully';
                    $result['output'] = Artisan::output();
                    break;

                case 'queue_status':
                    $result['queue'] = [
                        'default_connection' => config('queue.default'),
                        'failed_jobs' => DB::table('failed_jobs')->count(),
                        'pending_jobs' => DB::table('jobs')->count()
                    ];
                    $result['message'] = 'Queue status retrieved';
                    break;

                case 'info':
                default:
                    $result = [
                        'app_name' => config('app.name'),
                        'app_env' => config('app.env'),
                        'app_url' => config('app.url'),
                        'laravel_version' => app()->version(),
                        'php_version' => PHP_VERSION,
                        'database_connection' => config('database.default'),
                        'cache_driver' => config('cache.default'),
                        'queue_driver' => config('queue.default'),
                        'timezone' => config('app.timezone'),
                        'locale' => config('app.locale')
                    ];
                    break;
            }

            $content = [
                ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]
            ];

            // Add audio notification for 2025-03-26+
            if ($includeAudio && in_array($protocolVersion, ['2025-03-26', '2025-06-18'])) {
                try {
                    // Create a simple success beep (mock implementation)
                    $audioData = base64_encode('mock_audio_success_beep');
                    $content[] = [
                        'type' => 'audio',
                        'mimeType' => 'audio/wav',
                        'data' => $audioData,
                        'duration' => 0.5,
                        'name' => 'success_notification.wav'
                    ];
                } catch (\Exception $e) {
                    Log::warning('Audio generation failed: ' . $e->getMessage());
                }
            }

            return ['content' => $content];
        }, [
            'description' => 'Manage Laravel application with audio notifications',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['info', 'cache_clear', 'config_cache', 'queue_status'],
                        'description' => 'Management action to perform'
                    ],
                    'include_audio' => [
                        'type' => 'boolean',
                        'description' => 'Include audio notification (2025-03-26+)'
                    ]
                ]
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'requiresUserConfirmation' => true,
                'experimental' => false
            ]
        ]);

        // Database query tool with safety checks
        $this->toolRegistry->register('laravel_db_query', function($params, $context) {
            $query = $params['query'] ?? '';
            $readOnly = $params['read_only'] ?? true;

            if (empty($query)) {
                return ['error' => 'Query parameter is required'];
            }

            // Security: Only allow SELECT queries if read_only is true
            if ($readOnly && !preg_match('/^\s*SELECT\s/i', trim($query))) {
                return ['error' => 'Only SELECT queries allowed in read-only mode'];
            }

            try {
                $results = DB::select($query);
                return [
                    'query' => $query,
                    'results' => $results,
                    'count' => count($results),
                    'timestamp' => now()->toISOString()
                ];
            } catch (\Exception $e) {
                Log::error('Database query failed: ' . $e->getMessage());
                return [
                    'error' => 'Query execution failed',
                    'message' => $e->getMessage()
                ];
            }
        }, [
            'description' => 'Execute database queries with safety controls',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'SQL query to execute'],
                    'read_only' => ['type' => 'boolean', 'description' => 'Restrict to SELECT queries only']
                ],
                'required' => ['query']
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'requiresUserConfirmation' => true,
                'sensitive' => true
            ]
        ]);
    }

    /**
     * Register Laravel-specific prompts
     */
    private function registerLaravelPrompts(): void
    {
        $this->promptRegistry->register('laravel_debug_assistant', function($arguments, $context) {
            $issue = $arguments['issue'] ?? 'general debugging';
            $component = $arguments['component'] ?? 'application';
            $agencyName = $context['context_data']['name'] ?? 'Unknown Agency';

            return [
                'description' => 'Laravel debugging assistant with context awareness',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "You are a Laravel debugging expert for {$agencyName}. Help debug issues related to {$component}. Focus on practical solutions and best practices."
                            ]
                        ]
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "I need help debugging a Laravel issue: {$issue}. What should I check and what tools can help?"
                            ]
                        ]
                    ]
                ]
            ];
        }, [
            'description' => 'Generate debugging assistance prompts for Laravel applications',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'issue' => ['type' => 'string', 'description' => 'Description of the issue'],
                    'component' => [
                        'type' => 'string',
                        'enum' => ['application', 'database', 'queue', 'cache', 'routing', 'middleware', 'auth'],
                        'description' => 'Laravel component related to the issue'
                    ]
                ]
            ]
        ]);

        $this->promptRegistry->register('laravel_deployment_guide', function($arguments, $context) {
            $environment = $arguments['environment'] ?? 'production';
            $feature = $arguments['feature'] ?? 'general deployment';

            return [
                'description' => 'Laravel deployment guidance',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Guide me through deploying a Laravel application to {$environment} environment, focusing on {$feature}. Include best practices and security considerations."
                            ]
                        ]
                    ]
                ]
            ];
        }, [
            'description' => 'Generate deployment guidance for Laravel applications',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'environment' => [
                        'type' => 'string',
                        'enum' => ['development', 'staging', 'production'],
                        'description' => 'Target deployment environment'
                    ],
                    'feature' => ['type' => 'string', 'description' => 'Specific deployment aspect to focus on']
                ]
            ]
        ]);
    }

    /**
     * Register Laravel-specific resources
     */
    private function registerLaravelResources(): void
    {
        $this->resourceRegistry->register('laravel://app-state', function($uri, $context) {
            $appState = [
                'application' => [
                    'name' => config('app.name'),
                    'env' => config('app.env'),
                    'debug' => config('app.debug'),
                    'url' => config('app.url'),
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale')
                ],
                'database' => [
                    'default' => config('database.default'),
                    'connections' => array_keys(config('database.connections'))
                ],
                'cache' => [
                    'default' => config('cache.default'),
                    'stores' => array_keys(config('cache.stores'))
                ],
                'queue' => [
                    'default' => config('queue.default'),
                    'connections' => array_keys(config('queue.connections'))
                ],
                'session' => [
                    'driver' => config('session.driver'),
                    'lifetime' => config('session.lifetime')
                ],
                'mail' => [
                    'default' => config('mail.default'),
                    'mailers' => array_keys(config('mail.mailers'))
                ],
                'runtime' => [
                    'laravel_version' => app()->version(),
                    'php_version' => PHP_VERSION,
                    'memory_usage' => memory_get_usage(true),
                    'uptime' => time() - $_SERVER['REQUEST_TIME']
                ],
                'agency' => $context['context_data']['name'] ?? 'Unknown',
                'timestamp' => now()->toISOString()
            ];

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode($appState, JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }, [
            'name' => 'Laravel Application State',
            'description' => 'Comprehensive Laravel application configuration and runtime state',
            'mimeType' => 'application/json'
        ]);

        $this->resourceRegistry->register('laravel://routes', function($uri, $context) {
            $routes = [];

            foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
                $routes[] = [
                    'method' => implode('|', $route->methods()),
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                    'middleware' => $route->middleware(),
                    'parameters' => $route->parameterNames()
                ];
            }

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode([
                            'routes' => $routes,
                            'total_count' => count($routes),
                            'timestamp' => now()->toISOString()
                        ], JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }, [
            'name' => 'Laravel Routes',
            'description' => 'Complete list of registered Laravel routes with metadata',
            'mimeType' => 'application/json'
        ]);

        $this->resourceRegistry->register('laravel://logs', function($uri, $context) {
            $logPath = storage_path('logs/laravel.log');
            $logContent = 'No logs available';

            if (file_exists($logPath)) {
                $lines = file($logPath);
                if ($lines !== false) {
                    // Get last 100 lines of log file
                    $lastLines = array_slice($lines, -100);
                    $logContent = implode('', $lastLines);
                }
            }

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/plain',
                        'text' => $logContent
                    ]
                ]
            ];
        }, [
            'name' => 'Laravel Application Logs',
            'description' => 'Recent Laravel application logs (last 100 lines)',
            'mimeType' => 'text/plain'
        ]);

        // Resource template for model data
        $this->resourceRegistry->registerTemplate('laravel://models/{model}', function($uri, $context) {
            $modelName = basename($uri);
            $modelClass = "\\App\\Models\\{$modelName}";

            if (!class_exists($modelClass)) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode(['error' => "Model {$modelName} not found"])
                        ]
                    ]
                ];
            }

            try {
                $model = new $modelClass;
                $data = [
                    'model' => $modelName,
                    'table' => $model->getTable(),
                    'fillable' => $model->getFillable(),
                    'hidden' => $model->getHidden(),
                    'casts' => $model->getCasts(),
                    'total_count' => $model->count(),
                    'recent_records' => $model->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get()
                        ->toArray(),
                    'timestamp' => now()->toISOString()
                ];

                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode($data, JSON_PRETTY_PRINT)
                        ]
                    ]
                ];
            } catch (\Exception $e) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode([
                                'error' => 'Failed to load model data',
                                'message' => $e->getMessage()
                            ])
                        ]
                    ]
                ];
            }
        }, [
            'name' => 'Laravel Model Data',
            'description' => 'Access Laravel Eloquent model information and recent records',
            'mimeType' => 'application/json'
        ]);
    }

    /**
     * Check database connection status
     */
    private function checkDatabaseConnection(): string
    {
        try {
            DB::connection()->getPdo();
            return 'connected';
        } catch (\Exception $e) {
            return 'disconnected';
        }
    }

    /**
     * Get count of active MCP sessions
     */
    private function getActiveSessionCount(): int
    {
        try {
            return DB::table('mcp_sessions')
                ->where('expires_at', '>', now())
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Convert Laravel Request to PSR-7 Request
     */
    private function convertToPsrRequest(Request $request): \Psr\Http\Message\ServerRequestInterface
    {
        $psrFactory = app(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class);
        return $psrFactory->createRequest($request);
    }

    /**
     * Create PSR-7 Response
     */
    private function createPsrResponse(): \Psr\Http\Message\ResponseInterface
    {
        $psrFactory = app(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class);
        return $psrFactory->createResponse(new \Illuminate\Http\Response());
    }

    /**
     * Convert PSR-7 Response to Laravel Response
     */
    private function convertToLaravelResponse(\Psr\Http\Message\ResponseInterface $psrResponse): Response
    {
        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }
}

/*
 * ADDITIONAL SETUP INSTRUCTIONS:
 *
 * 1. Environment Configuration (.env):
 *
 * # Database
 * DB_CONNECTION=mysql
 * DB_HOST=127.0.0.1
 * DB_PORT=3306
 * DB_DATABASE=mcp_server
 * DB_USERNAME=your_username
 * DB_PASSWORD=your_password
 *
 * # MCP Server
 * APP_URL=https://your-domain.com
 * MCP_BASE_URL=https://your-domain.com
 *
 * # Social Authentication (Optional)
 * GOOGLE_CLIENT_ID=your_google_client_id
 * GOOGLE_CLIENT_SECRET=your_google_client_secret
 * LINKEDIN_CLIENT_ID=your_linkedin_client_id
 * LINKEDIN_CLIENT_SECRET=your_linkedin_client_secret
 * GITHUB_CLIENT_ID=your_github_client_id
 * GITHUB_CLIENT_SECRET=your_github_client_secret
 *
 * 2. Service Provider Registration (config/app.php):
 *
 * 'providers' => [
 *     // ... other providers
 *     Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
 * ],
 *
 * 3. Routes Registration (routes/web.php):
 *
 * use App\Http\Controllers\MCPController;
 *
 * // OAuth discovery endpoints
 * Route::get('/.well-known/oauth-authorization-server', [MCPController::class, 'authDiscovery']);
 * Route::get('/.well-known/oauth-protected-resource', [MCPController::class, 'resourceDiscovery']);
 *
 * // OAuth flow endpoints
 * Route::get('/oauth/authorize', [MCPController::class, 'oauthAuthorize']);
 * Route::post('/oauth/verify', [MCPController::class, 'oauthVerify']);
 * Route::post('/oauth/consent', [MCPController::class, 'oauthConsent']);
 * Route::post('/oauth/token', [MCPController::class, 'oauthToken']);
 * Route::post('/oauth/revoke', [MCPController::class, 'oauthRevoke']);
 * Route::post('/oauth/register', [MCPController::class, 'oauthRegister']);
 *
 * // Social authentication callbacks
 * Route::get('/oauth/google/callback', [MCPController::class, 'googleCallback']);
 * Route::get('/oauth/linkedin/callback', [MCPController::class, 'linkedinCallback']);
 * Route::get('/oauth/github/callback', [MCPController::class, 'githubCallback']);
 *
 * // Main MCP endpoint (requires authentication)
 * Route::match(['GET', 'POST'], '/mcp/{agencyUuid}/{sessID?}', [MCPController::class, 'handle'])
 *     ->middleware('mcp.auth');
 *
 * // Health and metrics endpoints
 * Route::get('/mcp-health', [MCPController::class, 'health']);
 * Route::get('/mcp-metrics', [MCPController::class, 'metrics'])->middleware('mcp.auth');
 *
 * 4. Database Migration:
 *
 * Import the schema from examples/database/database-schema.sql into your database.
 *
 * 5. Caching Configuration:
 *
 * php artisan config:cache
 * php artisan route:cache
 * php artisan view:cache
 *
 * 6. Testing:
 *
 * php artisan serve
 *
 * Visit http://localhost:8000/mcp-health to verify the server is running.
 * Visit http://localhost:8000/.well-known/oauth-authorization-server for OAuth discovery.
 *
 * FEATURES INCLUDED:
 *
 * ✅ OAuth 2.1 with RFC 8707 Resource Indicators (MCP 2025-06-18)
 * ✅ Multi-protocol version support (2024-11-05, 2025-03-26, 2025-06-18)
 * ✅ Social authentication (Google, LinkedIn, GitHub)
 * ✅ Tool annotations and audio content support (2025-03-26+)
 * ✅ Structured outputs and elicitation (2025-06-18)
 * ✅ Server-Sent Events and Streamable HTTP transport
 * ✅ Laravel-specific integrations (Eloquent, Cache, Queue, Artisan)
 * ✅ Comprehensive health and metrics endpoints
 * ✅ Security best practices and error handling
 * ✅ Production-ready logging and monitoring
 */
