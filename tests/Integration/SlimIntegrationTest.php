<?php

namespace Seolinkmap\Waasup\Tests\Integration;

use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Tests\TestCase;

class SlimIntegrationTest extends TestCase
{
    private SlimMCPProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = $this->createTestStorage();
        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        $config = [
            'server_info' => ['name' => 'Test MCP Server', 'version' => '1.0.0-test'],
            'auth' => ['context_types' => ['agency'], 'base_url' => 'https://localhost:8080']
        ];

        $this->provider = new SlimMCPProvider(
            $storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $this->responseFactory,
            $this->streamFactory,
            $config
        );
    }

    public function testGetServer(): void
    {
        $server = $this->provider->getServer();
        $this->assertInstanceOf(\Seolinkmap\Waasup\MCPSaaSServer::class, $server);
    }

    public function testGetAuthMiddleware(): void
    {
        $middleware = $this->provider->getAuthMiddleware();
        $this->assertInstanceOf(AuthMiddleware::class, $middleware);
    }

    public function testHandleMCP(): void
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2024-11-05'],
                'id' => 1
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->provider->handleMCP($request, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleAuthDiscovery(): void
    {
        $request = $this->createRequest('GET', '/.well-known/oauth-authorization-server');
        $response = $this->provider->handleAuthDiscovery($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testHandleResourceDiscovery(): void
    {
        $request = $this->createRequest('GET', '/.well-known/oauth-protected-resource');
        $response = $this->provider->handleResourceDiscovery($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
    }
}
