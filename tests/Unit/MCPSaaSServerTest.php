<?php

namespace Seolinkmap\Waasup\Tests\Unit;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

/*
 * @todo test version support for proper nomenclature
 */

class MCPSaaSServerTest extends TestCase
{
    private MCPSaaSServer $server;
    private $storage; // Keep reference to storage for debugging

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMockLogger();
        $this->storage = $this->createTestStorage(); // Store reference
        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        $this->server = new MCPSaaSServer(
            $this->storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'server_info' => [
                    'name' => 'Test MCP Server',
                    'version' => '1.0.0-test'
                ],
                'supported_versions' => ['2025-03-26', '2024-11-05'],
                'sse' => [
                    'test_mode' => true
                ]
            ],
            $this->logger
        );
    }

    /**
     * Create a fresh session for each test that needs one - more reliable than reusing
     */
    private function createFreshSession(): string
    {
        $initializeRequest = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'Test Client',
                    'version' => '1.0.0'
                ]
            ],
            'id' => rand(1, 1000) // Use random ID to avoid conflicts
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode($initializeRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        // Extract session ID from response header
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId, 'Session ID should be returned from initialize');

        // CRITICAL: Verify session was actually stored and is retrievable
        $storedSession = $this->storage->getSession($sessionId);
        $this->assertNotNull($storedSession, 'Session should be stored and retrievable after initialize');

        // Add extra verification
        $this->assertArrayHasKey('protocol_version', $storedSession, 'Session should have protocol_version');
        $this->assertEquals('2024-11-05', $storedSession['protocol_version'], 'Protocol version should match');

        // Debug output
        error_log("DEBUG: Created session {$sessionId} with data: " . json_encode($storedSession));

        return $sessionId;
    }

    protected function createMockLogger(): \Psr\Log\LoggerInterface
    {
        return new class () implements \Psr\Log\LoggerInterface {
            public array $logs = [];
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $record = ['level' => $level, 'message' => $message, 'context' => $context];
                $this->logs[] = $record;
                $this->records[] = $record;
            }

            public function emergency($message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }
            public function alert($message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }
            public function critical($message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }
            public function error($message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }
            public function warning($message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }
            public function notice($message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }
            public function info($message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }
            public function debug($message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function hasErrorRecords(): bool
            {
                return $this->hasLogLevel('error');
            }
            public function hasWarningRecords(): bool
            {
                return $this->hasLogLevel('warning');
            }
            public function hasCriticalRecords(): bool
            {
                return $this->hasLogLevel('critical');
            }

            private function hasLogLevel(string $level): bool
            {
                foreach ($this->logs as $log) {
                    if ($log['level'] === $level) {
                        return true;
                    }
                }
                return false;
            }
        };
    }

    public function testHandleOptionsRequest(): void
    {
        $request = $this->createRequest('OPTIONS', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET, POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testHandleGetWithoutSessionId(): void
    {
        $request = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32001);
    }

    public function testHandleGetWithValidSessionId(): void
    {
        $sessionId = $this->createFreshSession();

        $request = $this->createRequest('GET', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withHeader('Mcp-Session-Id', $sessionId);
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        // SSE connection should be established (200 status with event-stream content-type)
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/event-stream', $response->getHeaderLine('Content-Type'));
    }

    public function testHandlePostInitializeRequest(): void
    {
        $initializeRequest = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'Test Client',
                    'version' => '1.0.0'
                ]
            ],
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode($initializeRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->assertValidJsonRpc($response, 1);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('2024-11-05', $data['result']['protocolVersion']);
        $this->assertArrayHasKey('capabilities', $data['result']);
        $this->assertArrayHasKey('tools', $data['result']['capabilities']);
        $this->assertArrayHasKey('prompts', $data['result']['capabilities']);
        $this->assertArrayHasKey('resources', $data['result']['capabilities']);
        $this->assertArrayHasKey('serverInfo', $data['result']);
        $this->assertEquals('Test MCP Server', $data['result']['serverInfo']['name']);

        $this->assertNotEmpty($response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testHandlePostWithoutContext(): void
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            '{"jsonrpc":"2.0","method":"ping","id":1}'
        );

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32000);
    }

    public function testHandlePostWithInvalidJson(): void
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            '{invalid json'
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32700);
    }

    public function testHandlePostPingWithSession(): void
    {
        // Create a fresh session just for this test
        $sessionId = $this->createFreshSession();

        // Double-check the session exists right before we use it
        $sessionData = $this->storage->getSession($sessionId);
        $this->assertNotNull($sessionData, 'Session should exist right before ping test');

        $pingRequest = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($pingRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        // If this fails, let's get more info about what went wrong
        if ($response->getStatusCode() !== 202) {
            $body = (string) $response->getBody();
            $this->fail("Expected 202 but got {$response->getStatusCode()}. Response body: {$body}. Session exists: " . ($this->storage->getSession($sessionId) ? 'YES' : 'NO'));
        }

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostToolsListWithSession(): void
    {
        $sessionId = $this->createFreshSession();

        $toolsRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 2
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($toolsCallRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostPromptsListWithSession(): void
    {
        $sessionId = $this->createFreshSession();

        $promptsRequest = [
            'jsonrpc' => '2.0',
            'method' => 'prompts/list',
            'id' => 3
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($promptsRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostResourcesListWithSession(): void
    {
        $sessionId = $this->createFreshSession();

        $resourcesRequest = [
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'id' => 4
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($resourcesRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostToolsCallWithSession(): void
    {
        $sessionId = $this->createFreshSession();

        // Debug: Verify session still exists
        $sessionExists = $this->storage->getSession($sessionId);
        if (!$sessionExists) {
            $this->fail("Session {$sessionId} was lost after creation");
        }

        $toolsCallRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'test_tool',
                'arguments' => ['message' => 'test message']
            ],
            'id' => 3
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($toolsCallRequest)  // Fixed variable name
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        // Debug if it fails
        if ($response->getStatusCode() !== 202) {
            $body = (string) $response->getBody();
            $sessionStillExists = $this->storage->getSession($sessionId) ? 'YES' : 'NO';
            $this->fail("Expected 202 but got {$response->getStatusCode()}. Response: {$body}. Session exists: {$sessionStillExists}. Session ID: {$sessionId}");
        }

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostWithoutSession(): void
    {
        $pingRequest = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode($pingRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32001);
    }

    public function testHandlePostWithInvalidSession(): void
    {
        $pingRequest = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => 'invalid-session-id'
            ],
            json_encode($pingRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32001);
    }

    public function testHandleUnsupportedMethod(): void
    {
        $request = $this->createRequest('DELETE', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32002);
    }

    public function testHandlePutRequest(): void
    {
        $request = $this->createRequest('PUT', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertJsonRpcError($response, -32002);
    }

    public function testAddTool(): void
    {
        // Create fresh session for this test
        $sessionId = $this->createFreshSession();

        // Double-check the session exists
        $sessionData = $this->storage->getSession($sessionId);
        $this->assertNotNull($sessionData, 'Session should exist right before add tool test');

        $this->server->addTool(
            'custom_tool',
            function ($params, $context) {
                return [
                    'custom' => true,
                    'params' => $params,
                    'context_id' => $context['context_id'] ?? null
                ];
            },
            [
                'description' => 'Custom test tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'value' => ['type' => 'string']
                    ]
                ]
            ]
        );

        $toolsRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'custom_tool',
                'arguments' => ['value' => 'test']
            ],
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($toolsRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        // If this fails, let's get more info
        if ($response->getStatusCode() !== 202) {
            $body = (string) $response->getBody();
            $this->fail("Expected 202 but got {$response->getStatusCode()}. Response body: {$body}. Session exists: " . ($this->storage->getSession($sessionId) ? 'YES' : 'NO'));
        }

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testAddPrompt(): void
    {
        $this->server->addPrompt(
            'custom_prompt',
            function ($arguments, $context) {
                return [
                    'description' => 'Custom test prompt',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => 'This is a custom prompt'
                            ]
                        ]
                    ]
                ];
            },
            [
                'description' => 'Custom test prompt'
            ]
        );

        $this->addToAssertionCount(1);
    }

    public function testAddResource(): void
    {
        $this->server->addResource(
            'custom://resource',
            function ($uri, $context) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'text/plain',
                            'text' => 'Custom resource content'
                        ]
                    ]
                ];
            },
            [
                'name' => 'Custom Resource',
                'description' => 'Custom test resource'
            ]
        );

        $this->addToAssertionCount(1);
    }

    public function testSetContext(): void
    {
        $testContext = [
            'test' => 'context',
            'agency_id' => 123,
            'custom_data' => ['key' => 'value']
        ];

        $this->server->setContext($testContext);
        $this->addToAssertionCount(1);
    }

    public function testLoggingOnError(): void
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            '{invalid json'
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $this->server->handle($request, $this->createResponse());

        $this->assertTrue($this->logger->hasErrorRecords());

        $errorRecords = $this->logger->records;
        $found = false;
        foreach ($errorRecords as $record) {
            if (str_contains($record['message'], 'JSON-RPC processing error')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected error log message not found');
    }

    public function testLoggingOnAuthenticationFailure(): void
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            '{"jsonrpc":"2.0","method":"ping","id":1}'
        );

        $this->server->handle($request, $this->createResponse());

        $this->assertTrue($this->logger->hasWarningRecords());
    }

    public function testErrorResponseFormat(): void
    {
        $request = $this->createRequest('DELETE', '/mcp/550e8400-e29b-41d4-a716-446655440000');
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));

        $data = $this->assertJsonRpcError($response, -32002);
        $this->assertStringContainsString('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT.', $data['error']['message']);
    }

    public function testUnexpectedExceptionHandling(): void
    {
        $sessionId = $this->createFreshSession();

        // Use the same storage instance to avoid conflicts
        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        $toolRegistry->register(
            'broken_tool',
            function () {
                throw new \RuntimeException('Unexpected error');
            }
        );

        $brokenServer = new MCPSaaSServer(
            $this->storage, // Use same storage instance
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
            'sse' => [
                'test_mode' => true
            ]
            ],
            $this->logger
        );

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'broken_tool', 'arguments' => []],
                'id' => 1
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $brokenServer->handle($request, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());
        $this->assertTrue(true, 'Exception was handled gracefully');
    }

    public function testInitializeWithFutureProtocolVersion(): void
    {
        $initializeRequest = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2038-01-01',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Future Client', 'version' => '2.0.0']
            ],
            'id' => 1
        ];

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode($initializeRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->assertJsonRpcSuccess($response, 1);

        $this->assertEquals('2025-03-26', $data['result']['protocolVersion']);
    }

    public function testCorsHeaders(): void
    {
        $requestBody = json_encode(
            [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
            'id' => 1
            ]
        );

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            $requestBody
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('POST, GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testHandlePostToolsListWithSessionDEBUG(): void
    {
        // Create fresh session and verify it works
        $sessionId = $this->createFreshSession();
        $sessionExists = $this->storage->getSession($sessionId);
        $this->assertNotNull($sessionExists, "Session should exist after creation");

        echo "\n=== DEBUG TOOLS LIST TEST ===\n";
        echo "Session ID: {$sessionId}\n";
        echo "Session data: " . json_encode($sessionExists) . "\n";

        // Test the exact same request that's failing
        $toolsRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 2
        ];

        echo "Request JSON: " . json_encode($toolsRequest) . "\n";

        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($toolsRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        // Show all headers
        echo "Request headers:\n";
        foreach ($request->getHeaders() as $name => $values) {
            echo "  {$name}: " . implode(', ', $values) . "\n";
        }

        // Verify session still exists right before request
        $sessionStillExists = $this->storage->getSession($sessionId);
        echo "Session exists before request: " . ($sessionStillExists ? 'YES' : 'NO') . "\n";

        // Make the request
        $response = $this->server->handle($request, $this->createResponse());

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        echo "Response status: {$statusCode}\n";
        echo "Response body: {$responseBody}\n";
        echo "Response headers:\n";
        foreach ($response->getHeaders() as $name => $values) {
            echo "  {$name}: " . implode(', ', $values) . "\n";
        }

        // If it's a 400, decode the error
        if ($statusCode === 400) {
            $errorData = json_decode($responseBody, true);
            echo "ERROR DETAILS:\n";
            echo "  Code: " . ($errorData['error']['code'] ?? 'unknown') . "\n";
            echo "  Message: " . ($errorData['error']['message'] ?? 'unknown') . "\n";
            echo "  ID: " . ($errorData['id'] ?? 'unknown') . "\n";
        }

        echo "=== END DEBUG ===\n";

        // Now compare with a working tools/call request
        echo "\n=== COMPARE WITH WORKING TOOLS/CALL ===\n";

        $toolsCallRequest = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'test_tool',
                'arguments' => ['message' => 'test message']
            ],
            'id' => 3
        ];

        $callRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($toolsCallRequest)
        );
        $callRequest = $callRequest->withAttribute('mcp_context', $this->createTestContext());

        $callResponse = $this->server->handle($callRequest, $this->createResponse());

        echo "Tools/call status: " . $callResponse->getStatusCode() . "\n";
        echo "Tools/call body: " . (string) $callResponse->getBody() . "\n";

        // Fail with detailed info
        $this->fail("Tools/list returned {$statusCode} instead of 202. See debug output above.");
    }
}
