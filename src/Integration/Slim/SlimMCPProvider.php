<?php

namespace Seolinkmap\Waasup\Integration\Slim;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Seolinkmap\Waasup\Discovery\WellKnownProvider;
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

/**
 * Slim Framework 4 integration for WasSuP
 */
class SlimMCPProvider
{
    private MCPSaaSServer $mcpServer;
    private AuthMiddleware $authMiddleware;
    private WellKnownProvider $discoveryProvider;

    public function __construct(
        StorageInterface $storage,
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $logger = $logger ?? new NullLogger();

        $this->mcpServer = new MCPSaaSServer($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $config, $logger);

        $this->authMiddleware = new AuthMiddleware(
            $storage,
            $responseFactory,
            $streamFactory,
            $config
        );

        $this->discoveryProvider = new WellKnownProvider($config);
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
     * MCP endpoint handler
     */
    public function handleMCP(Request $request, Response $response): Response
    {
        return $this->mcpServer->handle($request, $response);
    }

    /**
     * OAuth authorization server discovery
     */
    public function handleAuthDiscovery(Request $request, Response $response): Response
    {
        return $this->discoveryProvider->authorizationServer($request, $response);
    }

    /**
     * OAuth protected resource discovery
     */
    public function handleResourceDiscovery(Request $request, Response $response): Response
    {
        return $this->discoveryProvider->protectedResource($request, $response);
    }
}
