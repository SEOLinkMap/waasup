<?php

namespace Seolinkmap\Waasup\Integration\Laravel;

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

        // Register Laravel MCP provider (like SlimMCPProvider)
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
                    $config,
                    $app->make(LoggerInterface::class)
                );
            }
        );

        // Register auth middleware
        $this->app->singleton(
            AuthMiddleware::class,
            function ($app) {
                $config = [
                'context_types' => ['agency'],
                'base_url' => config('app.url')
                ];

                return new AuthMiddleware(
                    $app->make(DatabaseStorage::class),
                    $app->make(ResponseFactoryInterface::class),
                    $app->make(StreamFactoryInterface::class),
                    $config
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
        $router->aliasMiddleware('mcp.auth', AuthMiddleware::class);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            MCPSaaSServer::class,
            LaravelMCPProvider::class,
            ToolRegistry::class,
            PromptRegistry::class,
            ResourceRegistry::class,
            DatabaseStorage::class,
            AuthMiddleware::class,
            WellKnownProvider::class,
            ResponseFactoryInterface::class,
            StreamFactoryInterface::class,
            PsrHttpFactory::class,
        ];
    }
}

/**
 * Laravel-specific MCP provider (matches SlimMCPProvider pattern)
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
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->mcpServer = new MCPSaaSServer($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $config, $logger);
        $this->authMiddleware = new AuthMiddleware($storage, $responseFactory, $streamFactory, $config['auth'] ?? []);
        $this->discoveryProvider = new WellKnownProvider($config['discovery'] ?? []);
        $this->psrFactory = app(PsrHttpFactory::class);
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
     * Handle MCP requests (Laravel-style)
     */
    public function handleMCP(Request $request): Response
    {
        // Convert Laravel request to PSR-7
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new \Illuminate\Http\Response());

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
        $psrResponse = $this->psrFactory->createResponse(new \Illuminate\Http\Response());

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
        $psrResponse = $this->psrFactory->createResponse(new \Illuminate\Http\Response());

        $psrResponse = $this->discoveryProvider->protectedResource($psrRequest, $psrResponse);

        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }
}
