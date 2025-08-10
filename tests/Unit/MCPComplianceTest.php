<?php

namespace Seolinkmap\Waasup\Tests\Unit;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * MCP Specification Compliance & Interoperability Tests
 *
 * Validates adherence to MCP protocol versions 2024-11-05, 2025-03-26, and 2025-06-18
 * ensuring proper version negotiation, transport compatibility, and feature support.
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

        // Add tool with annotations for 2025-03-26+ testing
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

    // ===== VERSION COMPATIBILITY TESTS =====

    /**
     * Test backward compatibility support across protocol versions
     */
    public function testBackwardCompatibilitySupport(): void
    {
        $versions = ['2025-06-18', '2025-03-26', '2024-11-05'];

        foreach ($versions as $version) {
            $this->requestIdCounter = 0; // Reset for each version test

            $initRequest = $this->createInitializeRequest($version);
            $response = $this->server->handle($initRequest, $this->createResponse());

            $this->assertEquals(200, $response->getStatusCode(), "Initialize failed for version {$version}");

            $data = $this->assertJsonRpcSuccess($response, 1);
            $this->assertEquals($version, $data['result']['protocolVersion'], "Version negotiation failed for {$version}");

            // Verify capabilities are appropriate for each version
            $capabilities = $data['result']['capabilities'];
            $this->assertVersionSpecificCapabilities($version, $capabilities);
        }
    }

    /**
     * Test version downgrade when client requests unsupported future version
     */
    public function testVersionDowngrade(): void
    {
        $this->requestIdCounter = 0; // Reset counter for this test

        $futureVersion = '2030-01-01';
        $initRequest = $this->createInitializeRequest($futureVersion);
        $response = $this->server->handle($initRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->assertJsonRpcSuccess($response, 1);

        // Should downgrade to latest supported version
        $this->assertEquals('2025-06-18', $data['result']['protocolVersion']);
    }

    // ===== CROSS-TRANSPORT COMPATIBILITY TESTS =====

    /**
     * Test transition from HTTP+SSE (2024-11-05) to Streamable HTTP (2025-03-26+)
     */
    public function testHttpSseToStreamableHttpTransition(): void
    {
        // Ensure clean session state for streaming tests
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Test that 2024-11-05 behavior is maintained
        $this->requestIdCounter = 0;
        $legacySessionId = $this->initializeSessionWithVersion('2024-11-05');

        // Test GET request for SSE connection (2024-11-05 behavior)
        $sseRequest = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Mcp-Session-Id', $legacySessionId);
        $sseRequest = $sseRequest->withAttribute('mcp_context', $this->createTestContext());

        $sseResponse = $this->server->handle($sseRequest, $this->createResponse());
        $this->assertEquals(200, $sseResponse->getStatusCode());
        $this->assertEquals('text/event-stream', $sseResponse->getHeaderLine('Content-Type'));

        // Test that 2025-03-26+ behavior works
        $this->requestIdCounter = 0;
        $modernSessionId = $this->initializeSessionWithVersion('2025-03-26');

        // Test GET request for Streamable HTTP connection
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
        // Test that the server gracefully handles transport-specific failures
        $sessionId = $this->initializeSessionWithVersion('2025-03-26');

        // Test request without proper session handling
        $request = $this->createRequest('POST', '/mcp/550e8400-e29b-41d4-a716-446655440000')
            ->withHeader('Content-Type', 'application/json')
            ->withoutHeader('Mcp-Session-Id'); // Missing session ID
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32001); // Session required error
    }

    // ===== CLIENT-SERVER COMPATIBILITY TESTS =====

    /**
     * Test support for legacy clients (2024-11-05)
     */
    public function testLegacyClientSupport(): void
    {
        $this->requestIdCounter = 0;
        $sessionId = $this->initializeSessionWithVersion('2024-11-05');

        // Test tools/list without tool annotations (2024-11-05 behavior)
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

        // Verify response is queued (legacy behavior)
        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('queued', $responseData['status']);
    }

    /**
     * Test modern client features (2025-03-26+)
     */
    public function testModernClientFeatures(): void
    {
        // Test 2025-03-26 features
        $this->requestIdCounter = 0;
        $sessionId2025 = $this->initializeSessionWithVersion('2025-03-26');

        // Test JSON-RPC batching (2025-03-26 only)
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
        $this->assertContains($batchResponse->getStatusCode(), [200, 202]); // Both are valid for batching

        // Test 2025-06-18 features (no batching)
        $this->requestIdCounter = 0;
        $sessionId2025_06 = $this->initializeSessionWithVersion('2025-06-18');

        // JSON-RPC batching was REMOVED in MCP 2025-06-18 spec
        // The server should reject batch requests for this version
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
        // Test protocol version header requirements for 2025-06-18
        $this->requestIdCounter = 0;
        $sessionId = $this->initializeSessionWithVersion('2025-06-18');

        // Request without MCP-Protocol-Version header should fail for 2025-06-18
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

        // Context without protocol_version should cause validation error
        $context = $this->createTestContext();
        unset($context['protocol_version']);
        $requestWithoutHeader = $requestWithoutHeader->withAttribute('mcp_context', $context);

        $response = $this->server->handle($requestWithoutHeader, $this->createResponse());
        $this->assertEquals(400, $response->getStatusCode());

        // Request with proper header should work
        $requestWithHeader = $requestWithoutHeader
            ->withHeader('MCP-Protocol-Version', '2025-06-18')
            ->withAttribute('mcp_context', $this->createTestContext(['protocol_version' => '2025-06-18']));

        $responseWithHeader = $this->server->handle($requestWithHeader, $this->createResponse());
        $this->assertEquals(202, $responseWithHeader->getStatusCode());
    }

    // ===== HELPER METHODS =====

    private function createInitializeRequest(string $protocolVersion): \Psr\Http\Message\ServerRequestInterface
    {
        $headers = ['Content-Type' => 'application/json'];
        $context = $this->createTestContext();

        // Add protocol version header for 2025-06-18
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

        // Add protocol version header for 2025-06-18
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
        $this->requestIdCounter = 0; // Reset for clean session

        $initRequest = $this->createInitializeRequest($version);

        // Prevent session issues in testing by ensuring clean state
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
        // All versions should have basic capabilities
        $this->assertArrayHasKey('tools', $capabilities);
        $this->assertArrayHasKey('prompts', $capabilities);
        $this->assertArrayHasKey('resources', $capabilities);

        switch ($version) {
            case '2024-11-05':
                // 2024-11-05 should NOT have completions capability
                $this->assertArrayNotHasKey('completions', $capabilities);
                break;

            case '2025-03-26':
                // 2025-03-26 should have completions capability
                $this->assertArrayHasKey('completions', $capabilities);
                // Should NOT have elicitation
                $this->assertArrayNotHasKey('elicitation', $capabilities);
                break;

            case '2025-06-18':
                // 2025-06-18 should have both completions and elicitation
                $this->assertArrayHasKey('completions', $capabilities);
                $this->assertArrayHasKey('elicitation', $capabilities);
                break;
        }
    }
}
