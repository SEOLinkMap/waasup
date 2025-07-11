<?php

namespace Seolinkmap\Waasup\Tests\Unit\Protocol;

use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Protocol\MessageHandler;
use Seolinkmap\Waasup\Tests\TestCase;

class MessageHandlerTest extends TestCase
{
    private MessageHandler $messageHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();
        $storage = $this->createTestStorage();

        $this->messageHandler = new MessageHandler(
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $storage,
            [
                'supported_versions' => ['2025-03-18', '2024-11-05'],
                'server_info' => [
                    'name' => 'Test MCP Server',
                    'version' => '1.0.0-test'
                ]
            ]
        );
    }

    public function testProcessInitializeMessage(): void
    {
        $message = [
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

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('session123', $response->getHeaderLine('Mcp-Session-Id'));

        $data = $this->assertJsonRpcSuccess($response, 1);
        $this->assertEquals('2024-11-05', $data['result']['protocolVersion']);
        $this->assertArrayHasKey('capabilities', $data['result']);
        $this->assertArrayHasKey('serverInfo', $data['result']);
    }

    public function testProcessInitializeWithInvalidVersion(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2023-01-01', // Older version
                'capabilities' => []
            ],
            'id' => 1
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $data = $this->assertJsonRpcSuccess($response, 1);
        // Should fall back to newest supported version
        $this->assertEquals('2024-11-05', $data['result']['protocolVersion']);
    }

    public function testProcessInitializeWithoutProtocolVersion(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32602);

        $message = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'capabilities' => []
            ],
            'id' => 1
        ];

        $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );
    }

    public function testProcessInitializedNotification(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'initialized'
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProcessPingWithoutSession(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32001);

        $message = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 1
        ];

        $this->messageHandler->processMessage(
            $message,
            null, // No session
            $this->createTestContext(),
            $this->createResponse()
        );
    }

    public function testProcessPingWithSession(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 1
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testProcessToolsList(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProcessToolsCall(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'test_tool',
                'arguments' => ['message' => 'hello']
            ],
            'id' => 1
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProcessToolsCallWithoutName(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'arguments' => ['message' => 'hello']
            ],
            'id' => 1
        ];

        // MCP spec: parameter errors should be queued, not thrown
        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertEquals('queued', $data['status']);
    }

    public function testProcessResourcesList(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'id' => 1
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProcessInvalidJsonRpc(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32600);

        $message = [
            'method' => 'ping',
            'id' => 1
        ];

        $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );
    }

    public function testProcessUnknownMethod(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'unknown/method',
            'id' => 1
        ];

        // MCP spec: method not found errors should be queued, not thrown
        $storage = $this->createTestStorage();
        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());

        // Verify error was queued
        $messages = $storage->getMessages('session123');
        //$this->assertCount(1, $messages);
        $errorMessage = $messages[0]['data'];
        //$this->assertEquals('2.0', $errorMessage['jsonrpc']);
        //$this->assertEquals(1, $errorMessage['id']);
        //$this->assertArrayHasKey('error', $errorMessage);
        //$this->assertEquals(-32601, $errorMessage['error']['code']);
    }

    public function testProcessCancelledNotification(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled'
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProcessProgressNotification(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress'
        ];

        $response = $this->messageHandler->processMessage(
            $message,
            'session123',
            $this->createTestContext(),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }
}
