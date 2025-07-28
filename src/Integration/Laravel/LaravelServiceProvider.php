<?php

namespace Seolinkmap\Waasup\Integration\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Seolinkmap\Waasup\Discovery\WellKnownProvider;
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Laravel 9.0+ integration for WasSuP
 */
class LaravelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register PSR-17 factories
        $this->app->singleton(ResponseFactoryInterface::class, Psr17Factory::class);
        $this->app->singleton(StreamFactoryInterface::class, Psr17Factory::class);

        // Register PSR-7 bridge
        $this->app->singleton(
            PsrHttpFactory::class,
            function ($app) {
                return new PsrHttpFactory(
                    $app->make(Psr17Factory::class),
                    $app->make(Psr17Factory::class),
                    $app->make(Psr17Factory::class),
                    $app->make(Psr17Factory::class)
                );
            }
        );

        // Register MCP registries
        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(PromptRegistry::class);
        $this->app->singleton(ResourceRegistry::class);

        // Register storage
        $this->app->singleton(
            DatabaseStorage::class,
            function ($app) {
                $pdo = $app['db']->connection()->getPdo();
                return new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);
            }
        );

        // Register discovery provider
        $this->app->singleton(WellKnownProvider::class);

        // Register main MCP server
        $this->app->singleton(
            MCPSaaSServer::class,
            function ($app) {
                $config = [
                'server_info' => [
                    'name' => config('app.name') . ' MCP Server',
                    'version' => '1.0.0'
                ],
                'auth' => [
                    'context_types' => ['agency'],
                    'base_url' => config('app.url')
                ]
                ];

                return new MCPSaaSServer(
                    $app->make(DatabaseStorage::class),
                    $app->make(ToolRegistry::class),
                    $app->make(PromptRegistry::class),
                    $app->make(ResourceRegistry::class),
                    $config,
                    $app->make(LoggerInterface::class)
                );
            }
        );

        // Register Laravel MCP provider
        $this->app->singleton(
            LaravelMCPProvider::class,
            function ($app) {
                $config = [
                'server_info' => [
                    'name' => config('app.name') . ' MCP Server',
                    'version' => '1.0.0'
                ],
                'auth' => [
                    'context_types' => ['agency'],
                    'base_url' => config('app.url')
                ]
                ];

                return new LaravelMCPProvider(
                    $app->make(DatabaseStorage::class),
                    $app->make(ToolRegistry::class),
                    $app->make(PromptRegistry::class),
                    $app->make(ResourceRegistry::class),
                    $app->make(ResponseFactoryInterface::class),
                    $app->make(StreamFactoryInterface::class),
                    $app->make(PsrHttpFactory::class),
                    $config,
                    $app->make(LoggerInterface::class)
                );
            }
        );

        // Register Laravel middleware wrapper
        $this->app->singleton(
            LaravelMCPAuthMiddleware::class,
            function ($app) {
                return new LaravelMCPAuthMiddleware(
                    $app->make(LaravelMCPProvider::class),
                    $app->make(PsrHttpFactory::class)
                );
            }
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('mcp.auth', LaravelMCPAuthMiddleware::class);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            MCPSaaSServer::class,
            LaravelMCPProvider::class,
            LaravelMCPAuthMiddleware::class,
            ToolRegistry::class,
            PromptRegistry::class,
            ResourceRegistry::class,
            DatabaseStorage::class,
            WellKnownProvider::class,
            ResponseFactoryInterface::class,
            StreamFactoryInterface::class,
            PsrHttpFactory::class,
        ];
    }
}

/**
 * Laravel middleware wrapper for PSR-15 AuthMiddleware
 */
class LaravelMCPAuthMiddleware
{
    private LaravelMCPProvider $mcpProvider;
    private PsrHttpFactory $psrFactory;

    public function __construct(LaravelMCPProvider $mcpProvider, PsrHttpFactory $psrFactory)
    {
        $this->mcpProvider = $mcpProvider;
        $this->psrFactory = $psrFactory;
    }

    public function handle(Request $request, Closure $next)
    {
        // Convert Laravel request to PSR-7
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new Response());

        // Create PSR-15 request handler
        $handler = new class ($next, $this->psrFactory) implements \Psr\Http\Server\RequestHandlerInterface {
            private Closure $next;
            private PsrHttpFactory $psrFactory;

            public function __construct(Closure $next, PsrHttpFactory $psrFactory)
            {
                $this->next = $next;
                $this->psrFactory = $psrFactory;
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                // Convert PSR-7 request back to Laravel request for next middleware
                $laravelRequest = $this->psrFactory->createRequest($request);
                $laravelResponse = ($this->next)($laravelRequest);

                // Convert Laravel response to PSR-7
                return $this->psrFactory->createResponse($laravelResponse);
            }
        };

        // Run PSR-15 auth middleware
        $authMiddleware = $this->mcpProvider->getAuthMiddleware();
        $psrResponse = $authMiddleware($psrRequest, $handler);

        // Convert PSR-7 response back to Laravel response
        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }
}

/**
 * Laravel-specific MCP provider
 */
class LaravelMCPProvider
{
    private MCPSaaSServer $mcpServer;
    private AuthMiddleware $authMiddleware;
    private WellKnownProvider $discoveryProvider;
    private PsrHttpFactory $psrFactory;

    public function __construct(
        \Seolinkmap\Waasup\Storage\StorageInterface $storage,
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        PsrHttpFactory $psrFactory,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->psrFactory = $psrFactory;
        $this->mcpServer = new MCPSaaSServer($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $config, $logger);

        $authConfig = $config['auth'] ?? [];

        // Merge oauth_endpoints from discovery config into auth config
        if (isset($config['discovery']['oauth_endpoints'])) {
            $authConfig['oauth_endpoints'] = $config['discovery']['oauth_endpoints'];
        }

        $this->authMiddleware = new AuthMiddleware(
            $storage,
            $responseFactory,
            $streamFactory,
            $authConfig
        );
        $this->discoveryProvider = new WellKnownProvider($config['discovery'] ?? []);
    }

    /**
     * Get the MCP server instance
     */
    public function getServer(): MCPSaaSServer
    {
        return $this->mcpServer;
    }

    /**
     * Get the auth middleware
     */
    public function getAuthMiddleware(): AuthMiddleware
    {
        return $this->authMiddleware;
    }

    /**
     * Handle MCP requests
     */
    public function handleMCP(Request $request): Response
    {
        // Convert Laravel request to PSR-7
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new Response());

        // Handle with MCP server
        $psrResponse = $this->mcpServer->handle($psrRequest, $psrResponse);

        // Convert PSR-7 response back to Laravel response
        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }

    /**
     * OAuth authorization server discovery
     */
    public function handleAuthDiscovery(Request $request): Response
    {
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new Response());

        $psrResponse = $this->discoveryProvider->authorizationServer($psrRequest, $psrResponse);

        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }

    /**
     * OAuth protected resource discovery
     */
    public function handleResourceDiscovery(Request $request): Response
    {
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new Response());

        $psrResponse = $this->discoveryProvider->protectedResource($psrRequest, $psrResponse);

        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }
}
