<?php

namespace Seolinkmap\Waasup\Tests\Unit;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Tests\TestCase;

class MCPSaaSServerTest extends TestCase
{
    private MCPSaaSServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMockLogger();
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
                'server_info' => [
                    'name' => 'Test MCP Server',
                    'version' => '1.0.0-test'
                ],
                'supported_versions' => ['2025-03-18', '2024-11-05'],
                'sse' => [
                    'test_mode' => true
                ]
            ],
            $this->logger
        );
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
                'Mcp-Session-Id' => 'session123'
            ],
            json_encode($pingRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testHandlePostToolsListWithSession(): void
    {
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
                'Mcp-Session-Id' => 'session123'
            ],
            json_encode($toolsRequest)
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
                'Mcp-Session-Id' => 'session123'
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
                'Mcp-Session-Id' => 'session123'
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
                'Mcp-Session-Id' => 'session123'
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
                'Mcp-Session-Id' => 'session123'
            ],
            json_encode($toolsRequest)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());

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
        $storage = $this->createTestStorage();
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
            $storage,
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
                'Mcp-Session-Id' => 'session123'
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
                'protocolVersion' => '2026-01-01',
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

        $this->assertEquals('2025-03-18', $data['result']['protocolVersion']);
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
}
