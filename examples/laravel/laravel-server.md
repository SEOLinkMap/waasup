<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;

/**
 * Laravel MCP Server Controller
 *
 * Prerequisites:
 * 1. composer require seolinkmap/wassup nyholm/psr7 symfony/psr-http-message-bridge
 * 2. Add service provider to config/app.php
 * 3. Set up database and import schema from examples/database/database-schema.sql
 * 4. Configure database connection in .env
 * 5. Add routes to routes/web.php or routes/api.php
 *
 * Service Provider Registration (config/app.php):
 * 'providers' => [
 *     // ... other providers
 *     Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
 * ],
 *
 * Routes (routes/web.php):
 * Route::get('/.well-known/oauth-authorization-server', [MCPController::class, 'authDiscovery']);
 * Route::get('/.well-known/oauth-protected-resource', [MCPController::class, 'resourceDiscovery']);
 * Route::match(['GET', 'POST'], '/mcp/{agencyUuid}/{sessID?}', [MCPController::class, 'handle'])
 *     ->middleware('mcp.auth');
 * Route::get('/mcp-health', [MCPController::class, 'health']);
 */
class MCPController extends Controller
{
    private LaravelMCPProvider $mcpProvider;
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;

    public function __construct(
        LaravelMCPProvider $mcpProvider,
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry
    ) {
        $this->mcpProvider = $mcpProvider;
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->resourceRegistry = $resourceRegistry;

        // Register custom features
        $this->registerCustomFeatures();
    }

    /**
     * Handle OAuth authorization server discovery
     */
    public function authDiscovery(Request $request): Response
    {
        return $this->mcpProvider->handleAuthDiscovery($request);
    }

    /**
     * Handle OAuth protected resource discovery
     */
    public function resourceDiscovery(Request $request): Response
    {
        return $this->mcpProvider->handleResourceDiscovery($request);
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
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
            'laravel_version' => app()->version(),
            'database' => $this->checkDatabaseConnection(),
            'environment' => config('app.env')
        ];

        return response()->json($health);
    }

    /**
     * Register custom tools, prompts, and resources for your application
     */
    private function registerCustomFeatures(): void
    {
        // Register Laravel-specific tools
        $this->registerLaravelTools();
        $this->registerLaravelPrompts();
        $this->registerLaravelResources();
    }

    /**
     * Register Laravel-specific tools
     */
    private function registerLaravelTools(): void
    {
        // User statistics tool
        $this->toolRegistry->register('get_user_stats', function($params, $context) {
            return [
                'total_users' => \App\Models\User::count(),
                'active_users' => \App\Models\User::whereNotNull('email_verified_at')->count(),
                'recent_users' => \App\Models\User::where('created_at', '>=', now()->subDays(7))->count(),
                'agency' => $context['context_data']['name'] ?? 'Unknown',
                'timestamp' => now()->toISOString()
            ];
        }, [
            'description' => 'Get user statistics from Laravel application',
            'inputSchema' => ['type' => 'object']
        ]);

        // Application info tool
        $this->toolRegistry->register('get_app_info', function($params, $context) {
            return [
                'app_name' => config('app.name'),
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'database_connection' => config('database.default'),
                'cache_driver' => config('cache.default'),
                'queue_driver' => config('queue.default')
            ];
        }, [
            'description' => 'Get Laravel application information',
            'inputSchema' => ['type' => 'object']
        ]);

        // Cache management tool
        $this->toolRegistry->register('cache_info', function($params, $context) {
            $action = $params['action'] ?? 'info';

            switch ($action) {
                case 'clear':
                    \Illuminate\Support\Facades\Cache::flush();
                    return ['message' => 'Cache cleared successfully'];

                case 'forget':
                    $key = $params['key'] ?? null;
                    if ($key) {
                        \Illuminate\Support\Facades\Cache::forget($key);
                        return ['message' => "Cache key '{$key}' forgotten"];
                    }
                    return ['error' => 'Cache key required for forget action'];

                case 'info':
                default:
                    return [
                        'driver' => config('cache.default'),
                        'prefix' => config('cache.prefix'),
                        'timestamp' => now()->toISOString()
                    ];
            }
        }, [
            'description' => 'Manage Laravel cache',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['info', 'clear', 'forget'],
                        'description' => 'Cache action to perform'
                    ],
                    'key' => [
                        'type' => 'string',
                        'description' => 'Cache key (required for forget action)'
                    ]
                ]
            ]
        ]);

        // Queue info tool
        $this->toolRegistry->register('queue_info', function($params, $context) {
            return [
                'default_connection' => config('queue.default'),
                'connections' => array_keys(config('queue.connections')),
                'failed_jobs' => \Illuminate\Support\Facades\DB::table('failed_jobs')->count(),
                'timestamp' => now()->toISOString()
            ];
        }, [
            'description' => 'Get Laravel queue information',
            'inputSchema' => ['type' => 'object']
        ]);

        // Artisan command runner
        $this->toolRegistry->register('run_artisan', function($params, $context) {
            $command = $params['command'] ?? '';

            // Security: Only allow safe commands
            $allowedCommands = [
                'cache:clear',
                'config:clear',
                'route:clear',
                'view:clear',
                'queue:work --stop-when-empty',
                'migrate:status'
            ];

            if (!in_array($command, $allowedCommands)) {
                return ['error' => 'Command not allowed for security reasons'];
            }

            try {
                \Illuminate\Support\Facades\Artisan::call($command);
                $output = \Illuminate\Support\Facades\Artisan::output();

                return [
                    'command' => $command,
                    'output' => $output,
                    'status' => 'success',
                    'timestamp' => now()->toISOString()
                ];
            } catch (\Exception $e) {
                return [
                    'command' => $command,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                    'timestamp' => now()->toISOString()
                ];
            }
        }, [
            'description' => 'Run safe Artisan commands',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'enum' => [
                            'cache:clear',
                            'config:clear',
                            'route:clear',
                            'view:clear',
                            'queue:work --stop-when-empty',
                            'migrate:status'
                        ],
                        'description' => 'Artisan command to run'
                    ]
                ],
                'required' => ['command']
            ]
        ]);
    }

    /**
     * Register Laravel-specific prompts
     */
    private function registerLaravelPrompts(): void
    {
        $this->promptRegistry->register('laravel_help', function($arguments, $context) {
            $topic = $arguments['topic'] ?? 'general';

            return [
                'description' => 'Laravel application help',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Help me with my Laravel application regarding {$topic}. What tools and information are available through the MCP server?"
                            ]
                        ]
                    ]
                ]
            ];
        }, [
            'description' => 'Get help with Laravel application features',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => ['type' => 'string', 'description' => 'Topic to get help with']
                ]
            ]
        ]);

        $this->promptRegistry->register('debug_assistant', function($arguments, $context) {
            $issue = $arguments['issue'] ?? 'general debugging';
            $level = $arguments['level'] ?? 'info';

            return [
                'description' => 'Laravel debugging assistant',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "I need help debugging a Laravel issue: {$issue}. Debug level: {$level}. What should I check and what tools can help?"
                            ]
                        ]
                    ]
                ]
            ];
        }, [
            'description' => 'Get debugging assistance for Laravel issues',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'issue' => ['type' => 'string', 'description' => 'Description of the issue'],
                    'level' => [
                        'type' => 'string',
                        'enum' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
                        'description' => 'Debug level'
                    ]
                ]
            ]
        ]);
    }

    /**
     * Register Laravel-specific resources
     */
    private function registerLaravelResources(): void
    {
        $this->resourceRegistry->register('laravel://config', function($uri, $context) {
            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode([
                            'app' => [
                                'name' => config('app.name'),
                                'env' => config('app.env'),
                                'url' => config('app.url'),
                                'timezone' => config('app.timezone'),
                                'locale' => config('app.locale')
                            ],
                            'database' => [
                                'default' => config('database.default')
                            ],
                            'cache' => [
                                'default' => config('cache.default')
                            ],
                            'queue' => [
                                'default' => config('queue.default')
                            ],
                            'mail' => [
                                'default' => config('mail.default')
                            ]
                        ], JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }, [
            'name' => 'Laravel Configuration',
            'description' => 'Current Laravel application configuration',
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
                    'middleware' => $route->middleware()
                ];
            }

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode($routes, JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }, [
            'name' => 'Laravel Routes',
            'description' => 'List of all registered Laravel routes',
            'mimeType' => 'application/json'
        ]);

        $this->resourceRegistry->register('laravel://logs', function($uri, $context) {
            $logPath = storage_path('logs/laravel.log');
            $logContent = 'No logs available';

            if (file_exists($logPath)) {
                // Get last 50 lines of log file
                $lines = file($logPath);
                if ($lines !== false) {
                    $lastLines = array_slice($lines, -50);
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
            'name' => 'Laravel Logs',
            'description' => 'Recent Laravel application logs',
            'mimeType' => 'text/plain'
        ]);

        // Environment info resource
        $this->resourceRegistry->register('laravel://env', function($uri, $context) {
            $envInfo = [
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale')
            ];

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode($envInfo, JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }, [
            'name' => 'Environment Information',
            'description' => 'Laravel environment and system information',
            'mimeType' => 'application/json'
        ]);
    }

    /**
     * Check database connection
     */
    private function checkDatabaseConnection(): string
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            return 'connected';
        } catch (\Exception $e) {
            return 'disconnected';
        }
    }
}

/*
 * Additional Setup Instructions:
 *
 * 1. Environment Configuration (.env):
 *
 * DB_CONNECTION=mysql
 * DB_HOST=127.0.0.1
 * DB_PORT=3306
 * DB_DATABASE=mcp_server
 * DB_USERNAME=your_username
 * DB_PASSWORD=your_password
 *
 * 2. Service Provider Registration (config/app.php):
 *
 * 'providers' => [
 *     // ... other providers
 *     Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
 * ],
 *
 * 3. Routes Registration (routes/web.php or routes/api.php):
 *
 * use App\Http\Controllers\MCPController;
 *
 * // OAuth discovery endpoints
 * Route::get('/.well-known/oauth-authorization-server', [MCPController::class, 'authDiscovery']);
 * Route::get('/.well-known/oauth-protected-resource', [MCPController::class, 'resourceDiscovery']);
 *
 * // Main MCP endpoint (requires authentication)
 * Route::match(['GET', 'POST'], '/mcp/{agencyUuid}/{sessID?}', [MCPController::class, 'handle'])
 *     ->middleware('mcp.auth');
 *
 * // Health check (no auth required)
 * Route::get('/mcp-health', [MCPController::class, 'health']);
 *
 * 4. Database Migration:
 *
 * Import the schema from examples/database/database-schema.sql into your database.
 *
 * 5. Testing:
 *
 * php artisan serve
 *
 * Visit http://localhost:8000/mcp-health to verify the server is running.
 * Visit http://localhost:8000/.well-known/oauth-authorization-server for OAuth discovery.
 */
