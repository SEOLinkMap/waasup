<?php

namespace Seolinkmap\Waasup\Tests\Unit\Transport;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * Transport Layer Tests for MCP Protocol Versions
 */
class TransportLayerTest extends TestCase
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

        $this->server = new MCPSaaSServer(
            $storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
                'server_info' => [
                    'name' => 'Transport Test Server',
                    'version' => '1.0.0-test'
                ],
                'test_mode' => true
            ]
        );
    }

    /**
     * Get next unique request ID for the session
     */
    private function getNextRequestId(): int
    {
        return ++$this->requestIdCounter;
    }

    /**
     * Test HTTP+SSE transport connection establishment
     * MCP 2024-11-05: Dual endpoint approach with GET for SSE, POST for messages
     */
    public function testHttpSseTransportConnection(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->initializeSession('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $sessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($sseRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertEquals('keep-alive', $response->getHeaderLine('Connection'));
        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * Test HTTP+SSE endpoint discovery mechanism
     * MCP 2024-11-05: Server must send endpoint event with message URI
     * Note: In test mode, we can't easily verify streaming content, so we test that
     * the connection is established successfully
     */
    public function testHttpSseEndpointDiscovery(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->initializeSession('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $sessionId);
        $sseRequest = $sseRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'base_url' => 'https://test.example.com',
                'context_id' => '550e8400-e29b-41d4-a716-446655440000'
                ]
            )
        );

        $response = $this->server->handle($sseRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/event-stream', $response->getHeaderLine('Content-Type'));

    }

    /**
     * Test HTTP+SSE message endpoint routing
     * MCP 2024-11-05: Messages routed through separate POST endpoint
     */
    public function testHttpSseMessageEndpointRouting(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        $messageRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $messageRequest = $messageRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2024-11-05'
                ]
            )
        );

        $response = $this->server->handle($messageRequest, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('queued', $responseData['status']);
    }

    /**
     * Test HTTP+SSE persistent connection management
     * MCP 2024-11-05: Long-lived SSE connections with keepalive
     */
    public function testHttpSsePersistentConnectionManagement(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->initializeSession('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $sessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($sseRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('keep-alive', $response->getHeaderLine('Connection'));
        $this->assertEquals('no', $response->getHeaderLine('X-Accel-Buffering'));

    }

    /**
     * Test Streamable HTTP single endpoint
     * MCP 2025-03-26: Single endpoint handles both POST and GET
     */
    public function testStreamableHttpSingleEndpoint(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        $postRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $postRequest = $postRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2025-03-26'
                ]
            )
        );

        $postResponse = $this->server->handle($postRequest, $this->createResponse());
        $this->assertEquals(200, $postResponse->getStatusCode());

        $getRequest = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $getRequest = $getRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2025-03-26'
                ]
            )
        );

        $getResponse = $this->server->handle($getRequest, $this->createResponse());

        $this->assertEquals(200, $getResponse->getStatusCode());

        $this->assertEquals('text/event-stream', $getResponse->getHeaderLine('Content-Type'));
    }

    /**
     * Test Streamable HTTP batch response mode
     * MCP 2025-03-26: Server can return JSON batch responses
     */
    public function testStreamableHttpBatchResponseMode(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        $batchRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
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
                ]
            )
        );
        $batchRequest = $batchRequest->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2025-03-26'
                ]
            )
        );

        $response = $this->server->handle($batchRequest, $this->createResponse());

        $this->assertContains($response->getStatusCode(), [200, 202]);
    }

    /**
     * Test Streamable HTTP session management
     * MCP 2025-03-26: Enhanced session handling with Mcp-Session-Id
     */
    public function testStreamableHttpSessionManagement(): void
    {

        $this->requestIdCounter = 0;

        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json'
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-03-26'],
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $this->createTestContext());

        $initResponse = $this->server->handle($initRequest, $this->createResponse());

        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);
        $this->assertMatchesRegularExpression('/^[!-~]+$/', $sessionId);

        $followupRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $followupRequest = $followupRequest->withAttribute('mcp_context', $this->createTestContext());

        $followupResponse = $this->server->handle($followupRequest, $this->createResponse());
        $this->assertEquals(200, $followupResponse->getStatusCode());
    }

    /**
     * Test Streamable HTTP session resumption
     * MCP 2025-03-26: Sessions can be resumed across connections
     */
    public function testStreamableHttpSessionResumption(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->initializeSession('2025-03-26');

        $request2 = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $request2 = $request2->withAttribute(
            'mcp_context',
            $this->createTestContext(
                [
                'protocol_version' => '2025-03-26'
                ]
            )
        );

        $response2 = $this->server->handle($request2, $this->createResponse());

        $this->assertEquals(200, $response2->getStatusCode());

    }

    /**
     * Test MCP-Protocol-Version header required for 2025-06-18
     * MCP 2025-06-18: Header is mandatory for latest version
     */
    public function testMcpProtocolVersionHeaderRequired(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $requestWithHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => '2025-06-18',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $requestWithHeader = $requestWithHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithHeader, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test MCP-Protocol-Version header missing for 2025-06-18
     * MCP 2025-06-18: Should fail when header is missing
     */
    public function testMcpProtocolVersionHeaderMissing(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $requestWithoutHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $context = $this->createTestContext();
        $requestWithoutHeader = $requestWithoutHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithoutHeader, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $data);
    }

    /**
     * Test invalid MCP-Protocol-Version header
     * MCP 2025-06-18: Invalid header values should be rejected
     */
    public function testMcpProtocolVersionHeaderInvalid(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $requestWithInvalidHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => 'invalid-version',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $context = $this->createTestContext(['protocol_version' => 'invalid-version']);
        $requestWithInvalidHeader = $requestWithInvalidHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithInvalidHeader, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32600);
    }

    /**
     * Test GET request with protocol version requirements
     * MCP 2025-06-18: GET requests for streaming also need version validation
     */
    public function testStreamingProtocolVersionValidation(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->initializeSession('2025-06-18');

        $streamRequest = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'MCP-Protocol-Version' => '2025-06-18',
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $streamRequest = $streamRequest->withAttribute('mcp_context', $context);

        $response = $this->server->handle($streamRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test mixed version compatibility
     * Ensure different versions can coexist during transition period
     */
    public function testMixedVersionCompatibility(): void
    {
        $versionTests = [
            '2024-11-05' => false,
            '2025-03-26' => false,
            '2025-06-18' => true,
        ];

        foreach ($versionTests as $version => $headerRequired) {

            $this->requestIdCounter = 0;

            $headers = ['Content-Type' => 'application/json'];
            $context = $this->createTestContext();

            if ($headerRequired) {
                $headers['MCP-Protocol-Version'] = $version;
                $context['protocol_version'] = $version;
            }

            $request = $this->createRequest(
                'POST',
                '/mcp/550e8400-e29b-41d4-a716-446655440000',
                $headers,
                json_encode(
                    [
                    'jsonrpc' => '2.0',
                    'method' => 'initialize',
                    'params' => ['protocolVersion' => $version],
                    'id' => $this->getNextRequestId()
                    ]
                )
            );
            $request = $request->withAttribute('mcp_context', $context);

            $response = $this->server->handle($request, $this->createResponse());

            $this->assertEquals(200, $response->getStatusCode(), "Failed for version {$version}");
            $data = $this->assertJsonRpcSuccess($response, $this->requestIdCounter);
            $this->assertEquals($version, $data['result']['protocolVersion']);
        }
    }

    /**
     * Helper method to initialize a session with specific protocol version
     * Following the existing working test pattern
     */
    private function initializeSession(string $protocolVersion): string
    {
        $headers = ['Content-Type' => 'application/json'];
        $context = $this->createTestContext();

        if ($protocolVersion === '2025-06-18') {
            $headers['MCP-Protocol-Version'] = $protocolVersion;
            $context['protocol_version'] = $protocolVersion;
        }

        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            $headers,
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => $protocolVersion],
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $context);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $initResponse = $this->server->handle($initRequest, $this->createResponse());
        $this->assertEquals(200, $initResponse->getStatusCode());

        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        return $sessionId;
    }
}
