<?php

namespace Seolinkmap\Waasup\Tests\Unit;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * MCP Specification Compliance & Interoperability Tests
 */
class MCPComplianceTest extends TestCase
{
    private MCPSaaSServer $server;
    private int $requestIdCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $storage = $this->createTestStorage();
        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        $toolRegistry->register(
            'destructive_tool',
            function ($params, $context) {
                return ['action' => 'deleted', 'target' => $params['target'] ?? 'unknown'];
            },
            [
                'description' => 'Tool that performs destructive operations',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['target' => ['type' => 'string']],
                    'required' => ['target']
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'requiresUserConfirmation' => true
                ]
            ]
        );

        $this->server = new MCPSaaSServer(
            $storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
                'server_info' => [
                    'name' => 'MCP Compliance Test Server',
                    'version' => '1.0.0-test'
                ],
                'test_mode' => true
            ]
        );
    }

    private function getNextRequestId(): int
    {
        return ++$this->requestIdCounter;
    }

    /**
     * Test backward compatibility support across protocol versions
     */
    public function testBackwardCompatibilitySupport(): void
    {
        $versions = ['2025-06-18', '2025-03-26', '2024-11-05'];

        foreach ($versions as $version) {
            $this->requestIdCounter = 0;

            $initRequest = $this->createInitializeRequest($version);
            $response = $this->server->handle($initRequest, $this->createResponse());

            $this->assertEquals(200, $response->getStatusCode(), "Initialize failed for version {$version}");

            $data = $this->assertJsonRpcSuccess($response, 1);
            $this->assertEquals($version, $data['result']['protocolVersion'], "Version negotiation failed for {$version}");

            $capabilities = $data['result']['capabilities'];
            $this->assertVersionSpecificCapabilities($version, $capabilities);
        }
    }

    /**
     * Test version downgrade when client requests unsupported future version
     */
    public function testVersionDowngrade(): void
    {
        $this->requestIdCounter = 0;

        $futureVersion = '2030-01-01';
        $initRequest = $this->createInitializeRequest($futureVersion);
        $response = $this->server->handle($initRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->assertJsonRpcSuccess($response, 1);

        $this->assertEquals('2025-06-18', $data['result']['protocolVersion']);
    }

    /**
     * Test transition from HTTP+SSE (2024-11-05) to Streamable HTTP (2025-03-26+)
     */
    public function testHttpSseToStreamableHttpTransition(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->requestIdCounter = 0;
        $legacySessionId = $this->initializeSessionWithVersion('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $legacySessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext());

        $sseResponse = $this->server->handle($sseRequest, $this->createResponse());
        $this->assertEquals(200, $sseResponse->getStatusCode());
        $this->assertEquals('text/event-stream', $sseResponse->getHeaderLine('Content-Type'));

        $this->requestIdCounter = 0;
        $modernSessionId = $this->initializeSessionWithVersion('2025-03-26');

        $streamRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $modernSessionId);
        $streamRequest = $streamRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2025-03-26'
                ]
            )
        );

        $streamResponse = $this->server->handle($streamRequest, $this->createResponse());
        $this->assertEquals(200, $streamResponse->getStatusCode());
    }

    /**
     * Test transport fallback mechanisms
     */
    public function testTransportFallback(): void
    {

        $sessionId = $this->initializeSessionWithVersion('2025-03-26');

        $request = $this->createRequest('POST', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Content-Type', 'application/json')
            ->withoutHeader('Mcp-Session-Id');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(404, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32001);
    }

    /**
     * Test support for legacy clients (2024-11-05)
     */
    public function testLegacyClientSupport(): void
    {
        $this->requestIdCounter = 0;
        $sessionId = $this->initializeSessionWithVersion('2024-11-05');

        $toolsRequest = $this->createSessionRequest(
            $sessionId,
            [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => $this->getNextRequestId()
            ],
            '2024-11-05'
        );

        $response = $this->server->handle($toolsRequest, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('queued', $responseData['status']);
    }

    /**
     * Test modern client features (2025-03-26+)
     */
    public function testModernClientFeatures(): void
    {

        $this->requestIdCounter = 0;
        $sessionId2025 = $this->initializeSessionWithVersion('2025-03-26');

        $batchRequest = $this->createSessionRequest(
            $sessionId2025,
            [
            [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => $this->getNextRequestId()
            ]
            ],
            '2025-03-26'
        );

        $batchResponse = $this->server->handle($batchRequest, $this->createResponse());
        $this->assertContains($batchResponse->getStatusCode(), [200, 202]);

        $this->requestIdCounter = 0;
        $sessionId2025_06 = $this->initializeSessionWithVersion('2025-06-18');

        $batchData = [
            [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => $this->getNextRequestId()
            ]
        ];

        $invalidBatchRequest = $this->createSessionRequest($sessionId2025_06, $batchData, '2025-06-18');

        $invalidBatchResponse = $this->server->handle($invalidBatchRequest, $this->createResponse());

        $this->assertEquals(400, $invalidBatchResponse->getStatusCode());
    }

    /**
     * Test client-server version mismatch scenarios
     */
    public function testClientServerVersionMismatch(): void
    {

        $this->requestIdCounter = 0;
        $sessionId = $this->initializeSessionWithVersion('2025-06-18');

        $requestWithoutHeader = $this->createRequest('POST', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withBody(
                $this->streamFactory->createStream(
                    json_encode(
                        [
                        'jsonrpc' => '2.0',
                        'method' => 'ping',
                        'id' => $this->getNextRequestId()
                        ]
                    )
                )
            );

        $context = $this->createTestContext();
        unset($context['protocol_version']);
        $requestWithoutHeader = $requestWithoutHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithoutHeader, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());

        $requestWithHeader = $requestWithoutHeader
            ->withHeader('MCP-Protocol-Version', '2025-06-18')
            ->withAttribute('mcp_context', $this->createTestContext(['protocol_version' => '2025-06-18']))
            ->withBody(
                $this->streamFactory->createStream(
                    json_encode(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => $this->getNextRequestId()])
                )
            );

        $responseWithHeader = $this->server->handle($requestWithHeader, $this->createResponse());
        $this->assertEquals(200, $responseWithHeader->getStatusCode());
    }

    private function createInitializeRequest(string $protocolVersion): \Psr\Http\Message\ServerRequestInterface
    {
        $headers = ['Content-Type' => 'application/json'];
        $context = $this->createTestContext();

        if ($protocolVersion === '2025-06-18') {
            $headers['MCP-Protocol-Version'] = $protocolVersion;
            $context['protocol_version'] = $protocolVersion;
        }

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            $headers,
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $protocolVersion,
                    'capabilities' => [],
                    'clientInfo' => [
                        'name' => 'MCP Compliance Test Client',
                        'version' => '1.0.0-test'
                    ]
                ],
                'id' => $this->getNextRequestId()
                ]
            )
        );

        return $request->withAttribute('mcp_context', $context);
    }

    private function createSessionRequest(string $sessionId, array $data, ?string $version = null): \Psr\Http\Message\ServerRequestInterface
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Mcp-Session-Id' => $sessionId
        ];

        $context = $this->createTestContext();

        if ($version === '2025-06-18') {
            $headers['MCP-Protocol-Version'] = $version;
            $context['protocol_version'] = $version;
        }

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            $headers,
            json_encode($data)
        );

        return $request->withAttribute('mcp_context', $context);
    }

    private function initializeSessionWithVersion(string $version): string
    {
        $this->requestIdCounter = 0;

        $initRequest = $this->createInitializeRequest($version);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $response = $this->server->handle($initRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        return $sessionId;
    }

    private function assertVersionSpecificCapabilities(string $version, array $capabilities): void
    {

        $this->assertArrayHasKey('tools', $capabilities);
        $this->assertArrayHasKey('prompts', $capabilities);
        $this->assertArrayHasKey('resources', $capabilities);

        switch ($version) {
            case '2024-11-05':

                $this->assertArrayNotHasKey('completions', $capabilities);
                break;

            case '2025-03-26':

                $this->assertArrayHasKey('completions', $capabilities);

                $this->assertArrayNotHasKey('elicitation', $capabilities);
                break;

            case '2025-06-18':

                $this->assertArrayHasKey('completions', $capabilities);
                $this->assertArrayNotHasKey('elicitation', $capabilities);
                break;
        }
    }
}
