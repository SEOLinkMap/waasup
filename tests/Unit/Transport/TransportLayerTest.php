<?php

namespace Seolinkmap\Waasup\Tests\Unit\Transport;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * Transport Layer Tests for MCP Protocol Versions
 *
 * Tests transport behavior through the full server stack (like existing working tests)
 * rather than testing transport classes in isolation
 */
class TransportLayerTest extends TestCase
{
    private MCPSaaSServer $server;

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
                'sse' => ['test_mode' => true],  // This is key - following existing pattern
                'streamable_http' => ['test_mode' => true]
            ]
        );
    }

    // ========================================
    // HTTP+SSE Transport Tests (2024-11-05)
    // ========================================

    /**
     * Test HTTP+SSE transport connection establishment
     * MCP 2024-11-05: Dual endpoint approach with GET for SSE, POST for messages
     */
    public function testHttpSseTransportConnection(): void
    {
        // First establish session through initialize
        $sessionId = $this->initializeSession('2024-11-05');

        // Test SSE connection establishment (GET request)
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
     */
    public function testHttpSseEndpointDiscovery(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $sessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext([
            'base_url' => 'https://test.example.com',
            'context_id' => '550e8400-e29b-41d4-a716-446655440000'
        ]));

        $response = $this->server->handle($sseRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // In test mode, should contain endpoint event
        $this->assertStringContainsString('event: endpoint', $body);
        $this->assertStringContainsString('https://test.example.com/mcp/550e8400-e29b-41d4-a716-446655440000', $body);
    }

    /**
     * Test HTTP+SSE message endpoint routing
     * MCP 2024-11-05: Messages routed through separate POST endpoint
     */
    public function testHttpSseMessageEndpointRouting(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        // Send message via POST (should be queued for SSE delivery)
        $messageRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 1
            ])
        );
        $messageRequest = $messageRequest->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($messageRequest, $this->createResponse());

        // In HTTP+SSE mode, messages should be queued for SSE delivery
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
        $sessionId = $this->initializeSession('2024-11-05');

        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $sessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($sseRequest, $this->createResponse());

        $body = (string) $response->getBody();

        // In test mode, should include keepalive comments
        $this->assertStringContainsString(': keepalive', $body);

        // Verify proper SSE headers for persistent connection
        $this->assertEquals('keep-alive', $response->getHeaderLine('Connection'));
        $this->assertEquals('no', $response->getHeaderLine('X-Accel-Buffering'));
    }

    // ==========================================
    // Streamable HTTP Transport Tests (2025-03-26)
    // ==========================================

    /**
     * Test Streamable HTTP single endpoint
     * MCP 2025-03-26: Single endpoint handles both POST and GET
     */
    public function testStreamableHttpSingleEndpoint(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        // Test POST to single endpoint
        $postRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => 1
            ])
        );
        $postRequest = $postRequest->withAttribute('mcp_context', $this->createTestContext());

        $postResponse = $this->server->handle($postRequest, $this->createResponse());
        $this->assertEquals(202, $postResponse->getStatusCode());

        // Test GET to same endpoint for streaming
        $getRequest = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Accept' => 'text/event-stream',
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $getRequest = $getRequest->withAttribute('mcp_context', $this->createTestContext());

        $getResponse = $this->server->handle($getRequest, $this->createResponse());

        $this->assertEquals(200, $getResponse->getStatusCode());
        // For 2025-03-26, should use application/json content type
        $this->assertEquals('application/json', $getResponse->getHeaderLine('Content-Type'));
    }

    /**
     * Test Streamable HTTP batch response mode
     * MCP 2025-03-26: Server can return JSON batch responses
     */
    public function testStreamableHttpBatchResponseMode(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        // Test JSON-RPC batch request (supported in 2025-03-26)
        $batchRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                [
                    'jsonrpc' => '2.0',
                    'method' => 'ping',
                    'id' => 1
                ],
                [
                    'jsonrpc' => '2.0',
                    'method' => 'tools/list',
                    'id' => 2
                ]
            ])
        );
        $batchRequest = $batchRequest->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($batchRequest, $this->createResponse());

        // Should queue batch for processing
        $this->assertEquals(202, $response->getStatusCode());
    }

    /**
     * Test Streamable HTTP session management
     * MCP 2025-03-26: Enhanced session handling with Mcp-Session-Id
     */
    public function testStreamableHttpSessionManagement(): void
    {
        // Initialize without existing session
        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream'
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-03-26'],
                'id' => 1
            ])
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $this->createTestContext());

        $initResponse = $this->server->handle($initRequest, $this->createResponse());

        // Server should assign session ID
        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);
        $this->assertMatchesRegularExpression('/^[!-~]+$/', $sessionId); // ASCII printable chars

        // Use session ID in subsequent request
        $followupRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => 2
            ])
        );
        $followupRequest = $followupRequest->withAttribute('mcp_context', $this->createTestContext());

        $followupResponse = $this->server->handle($followupRequest, $this->createResponse());
        $this->assertEquals(202, $followupResponse->getStatusCode());
    }

    /**
     * Test Streamable HTTP session resumption
     * MCP 2025-03-26: Sessions can be resumed across connections
     */
    public function testStreamableHttpSessionResumption(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        // Second connection resuming same session
        $request2 = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Accept' => 'text/event-stream',
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $request2 = $request2->withAttribute('mcp_context', $this->createTestContext());

        $response2 = $this->server->handle($request2, $this->createResponse());

        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals($sessionId, $response2->getHeaderLine('Mcp-Session-Id'));
    }

    // ==============================================
    // Protocol Version Header Tests (2025-06-18)
    // ==============================================

    /**
     * Test MCP-Protocol-Version header required for 2025-06-18
     * MCP 2025-06-18: Header is mandatory for latest version
     */
    public function testMcpProtocolVersionHeaderRequired(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        // Request WITH required header
        $requestWithHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => '2025-06-18',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => 1
            ])
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $requestWithHeader = $requestWithHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithHeader, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    /**
     * Test MCP-Protocol-Version header missing for 2025-06-18
     * MCP 2025-06-18: Should fail when header is missing
     */
    public function testMcpProtocolVersionHeaderMissing(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        // Request WITHOUT required header (for 2025-06-18)
        $requestWithoutHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'id' => 1
            ])
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $requestWithoutHeader = $requestWithoutHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithoutHeader, $this->createResponse());

        // Should fail for 2025-06-18 without header
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32600);
    }

    /**
     * Test invalid MCP-Protocol-Version header
     * MCP 2025-06-18: Invalid header values should be rejected
     */
    public function testMcpProtocolVersionHeaderInvalid(): void
    {
        $requestWithInvalidHeader = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => 'invalid-version'
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18'],
                'id' => 1
            ])
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $requestWithInvalidHeader = $requestWithInvalidHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithInvalidHeader, $this->createResponse());

        // Should reject invalid protocol version
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32600);
    }

    /**
     * Test GET request with protocol version requirements
     * MCP 2025-06-18: GET requests for streaming also need version validation
     */
    public function testStreamingProtocolVersionValidation(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        // GET request for streaming with protocol version validation
        $streamRequest = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Accept' => 'text/event-stream',
                'MCP-Protocol-Version' => '2025-06-18',
                'Mcp-Session-Id' => $sessionId
            ]
        );
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);
        $streamRequest = $streamRequest->withAttribute('mcp_context', $context);

        $response = $this->server->handle($streamRequest, $this->createResponse());

        // Should succeed with proper version header
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test mixed version compatibility
     * Ensure different versions can coexist during transition period
     */
    public function testMixedVersionCompatibility(): void
    {
        $versionTests = [
            '2024-11-05' => false, // No header required
            '2025-03-26' => false, // No header required
            '2025-06-18' => true,  // Header required
        ];

        foreach ($versionTests as $version => $headerRequired) {
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
                json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'initialize',
                    'params' => ['protocolVersion' => $version],
                    'id' => 1
                ])
            );
            $request = $request->withAttribute('mcp_context', $context);

            $response = $this->server->handle($request, $this->createResponse());

            $this->assertEquals(200, $response->getStatusCode(), "Failed for version {$version}");
            $data = $this->assertJsonRpcSuccess($response, 1);
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

        // Add protocol version header for 2025-06-18
        if ($protocolVersion === '2025-06-18') {
            $headers['MCP-Protocol-Version'] = $protocolVersion;
            $context['protocol_version'] = $protocolVersion;
        }

        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            $headers,
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => $protocolVersion],
                'id' => 1
            ])
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $context);

        $initResponse = $this->server->handle($initRequest, $this->createResponse());
        $this->assertEquals(200, $initResponse->getStatusCode());

        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        return $sessionId;
    }
}
