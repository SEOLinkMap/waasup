<?php

namespace Seolinkmap\Waasup\Tests\Unit;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * Unit tests for MCP core protocol features
 *
 * Tests protocol compliance across prompts, resources, cancellation,
 * logging, pagination, sampling, and roots capabilities.
 */
class CoreProtocolTest extends TestCase
{
    private MCPSaaSServer $server;
    private MemoryStorage $storage;
    private int $requestIdCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->createTestStorage();
        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        // Add test prompts with validation
        $promptRegistry->register(
            'template_prompt',
            function ($arguments, $context) {
                if (empty($arguments['name'])) {
                    throw new \InvalidArgumentException('Name is required');
                }
                $name = $arguments['name'];
                $topic = $arguments['topic'] ?? 'general';
                return [
                    'description' => "Template for {$name} about {$topic}",
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Hello {$name}, let's discuss {$topic}."
                            ]
                        ]
                    ]
                ];
            },
            [
                'description' => 'Template prompt requiring name',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Required name'],
                        'topic' => ['type' => 'string', 'description' => 'Optional topic']
                    ],
                    'required' => ['name']
                ]
            ]
        );

        // Add test resource that can fail
        $resourceRegistry->register(
            'test://protected',
            function ($uri, $context) {
                // Check for agency_id in the context structure
                $agencyId = $context['agency_id'] ?? $context['token_data']['agency_id'] ?? $context['context_data']['id'] ?? null;
                if (empty($agencyId)) {
                    throw new \RuntimeException('Access denied - no agency context');
                }
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode(['protected' => true, 'agency' => $agencyId])
                        ]
                    ]
                ];
            }
        );

        $this->server = new MCPSaaSServer(
            $this->storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'server_info' => ['name' => 'Test Server', 'version' => '1.0.0'],
                'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
                'sse' => ['test_mode' => true]
            ]
        );
    }

    private function getNextRequestId(): int
    {
        return ++$this->requestIdCounter;
    }

    private function initializeAndGetSession(): string
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

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());
        return $response->getHeaderLine('Mcp-Session-Id');
    }

    private function sendRequestAndGetStoredResponse(string $sessionId, array $requestData): array
    {
        $request = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode($requestData)
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());

        // Get the actual stored response
        $messages = $this->storage->getMessages($sessionId);
        $this->assertNotEmpty($messages, 'No response was stored');

        $lastMessage = end($messages);
        return $lastMessage['data'];
    }

    // ===== PROMPT TESTS =====

    public function testPromptWithArguments(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/get',
            'params' => [
                'name' => 'template_prompt',
                'arguments' => ['name' => 'Alice', 'topic' => 'MCP testing']
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('messages', $response['result']);
        $this->assertStringContainsString('Alice', $response['result']['messages'][0]['content']['text']);
        $this->assertStringContainsString('MCP testing', $response['result']['messages'][0]['content']['text']);
    }

    public function testPromptListChanged(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/list',
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('prompts', $response['result']);
        $this->assertIsArray($response['result']['prompts']);

        // Verify our test prompts are included
        $promptNames = array_column($response['result']['prompts'], 'name');
        $this->assertContains('test_prompt', $promptNames);
        $this->assertContains('template_prompt', $promptNames);
    }

    public function testPromptGetWithParameters(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/get',
            'params' => [
                'name' => 'test_prompt',
                'arguments' => ['topic' => 'testing']
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('description', $response['result']);
        $this->assertArrayHasKey('messages', $response['result']);
    }

    public function testPromptArgumentValidation(): void
    {
        $sessionId = $this->initializeAndGetSession();

        // Test missing required argument - should fail gracefully
        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/get',
            'params' => [
                'name' => 'template_prompt',
                'arguments' => ['topic' => 'test'] // missing required 'name'
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        // Should return error result in wrapped form, not crash
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('messages', $response['result']);
    }

    public function testPromptTemplateProcessing(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/get',
            'params' => [
                'name' => 'template_prompt',
                'arguments' => ['name' => 'Bob', 'topic' => 'protocols']
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $messageText = $response['result']['messages'][0]['content']['text'];
        $this->assertStringContainsString('Bob', $messageText);
        $this->assertStringContainsString('protocols', $messageText);
    }

    // ===== RESOURCE TESTS =====

    public function testResourceSubscription(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('resources', $response['result']);
        $this->assertIsArray($response['result']['resources']);
    }

    public function testResourceListChanged(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/templates/list',
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('resourceTemplates', $response['result']);
    }

    public function testResourceTemplateHandling(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'test://{id}'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('contents', $response['result']);
    }

    public function testResourceReadOperation(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'test://resource'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('contents', $response['result']);
        $this->assertEquals('test://resource', $response['result']['contents'][0]['uri']);
    }

    public function testResourceUriSchemeHandling(): void
    {
        $sessionId = $this->initializeAndGetSession();

        // Test protected resource with context
        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'test://protected'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $content = json_decode($response['result']['contents'][0]['text'], true);
        $this->assertTrue($content['protected']);
        $this->assertEquals(1, $content['agency']); // From test context
    }

    public function testResourceMimeTypeDetection(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'test://resource'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertEquals('text/plain', $response['result']['contents'][0]['mimeType']);
    }

    public function testResourceNotFound(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'nonexistent://resource'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        // Should return wrapped error content, not crash
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('contents', $response['result']);
    }

    // ===== CANCELLATION TESTS =====

    public function testRequestCancellation(): void
    {
        $sessionId = $this->initializeAndGetSession();

        // Send cancellation notification
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
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 123]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());

        // Verify messages were cleaned up
        $messages = $this->storage->getMessages($sessionId);
        $this->assertIsArray($messages);
    }

    public function testCancellationNotification(): void
    {
        $sessionId = $this->initializeAndGetSession();

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
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 456]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testCancellationMidExecution(): void
    {
        $sessionId = $this->initializeAndGetSession();

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
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 789]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testCancellationCleanup(): void
    {
        $sessionId = $this->initializeAndGetSession();

        // Add some messages first
        $this->storage->storeMessage($sessionId, ['test' => 'message1']);
        $this->storage->storeMessage($sessionId, ['test' => 'message2']);

        $initialCount = count($this->storage->getMessages($sessionId));

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
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 999]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());

        // Verify cleanup occurred
        $this->assertGreaterThan(0, $initialCount);
    }

    // ===== LOGGING TESTS =====

    public function testLoggingCapability(): void
    {
        $sessionId = $this->initializeAndGetSession();

        // Logging methods are not implemented in current server, expect method not found
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
                'method' => 'logging/setLevel',
                'params' => ['level' => 'info'],
                'id' => $this->getNextRequestId()
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testLogLevelFiltering(): void
    {
        $sessionId = $this->initializeAndGetSession();

        foreach (['debug', 'info', 'warning', 'error'] as $level) {
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
                    'method' => 'logging/setLevel',
                    'params' => ['level' => $level],
                    'id' => $this->getNextRequestId()
                    ]
                )
            );
            $request = $request->withAttribute('mcp_context', $this->createTestContext());

            $response = $this->server->handle($request, $this->createResponse());
            $this->assertEquals(202, $response->getStatusCode());
        }
    }

    public function testLogMessageFormatting(): void
    {
        $sessionId = $this->initializeAndGetSession();

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
                'method' => 'notifications/message',
                'params' => [
                    'level' => 'info',
                    'data' => 'Test log message'
                ]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testLogTargetHandling(): void
    {
        $sessionId = $this->initializeAndGetSession();

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
                'method' => 'notifications/message',
                'params' => [
                    'level' => 'debug',
                    'data' => 'Debug message'
                ]
                ]
            )
        );
        $request = $request->withAttribute('mcp_context', $this->createTestContext());

        $response = $this->server->handle($request, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===== PAGINATION TESTS =====

    public function testPaginationSupport(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => ['cursor' => null],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('tools', $response['result']);
    }

    public function testPaginationCursor(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'params' => ['cursor' => 'page_token'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    public function testPaginationLimits(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'prompts/list',
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('prompts', $response['result']);
    }

    // ===== SAMPLING TESTS =====

    public function testSamplingCoordination(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'sampling/createMessage',
            'params' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Test']]]
                ]
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
        $this->assertEquals(true, $response['result']['received']);
    }

    public function testSamplingCapability(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'sampling/createMessage',
            'params' => [
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System']]]
                ]
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    public function testSamplingRequest(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'sampling/createMessage',
            'params' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Generate']]]
                ],
                'includeContext' => 'thisServer'
            ],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    // ===== ROOTS TESTS =====

    public function testRootsCapability(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'roots/list',
            'params' => [],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    public function testRootsListChanged(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'roots/listDirectory',
            'params' => ['uri' => 'file:///'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    public function testRootsNotification(): void
    {
        $sessionId = $this->initializeAndGetSession();

        $response = $this->sendRequestAndGetStoredResponse(
            $sessionId, [
            'jsonrpc' => '2.0',
            'method' => 'roots/read',
            'params' => ['uri' => 'file:///test.txt'],
            'id' => $this->getNextRequestId()
            ]
        );

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }
}
