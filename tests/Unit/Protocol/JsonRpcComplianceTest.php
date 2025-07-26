<?php

namespace Seolinkmap\Waasup\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Protocol\MessageHandler;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Slim\Psr7\Response;

class JsonRpcComplianceTest extends TestCase
{
    private MessageHandler $handler;
    private MemoryStorage $storage;
    private string $validSessionId;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $toolRegistry = new ToolRegistry();
        $promptRegistry = new PromptRegistry();
        $resourceRegistry = new ResourceRegistry();

        $this->handler = new MessageHandler(
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $this->storage,
            ['server_info' => ['name' => 'Test Server', 'version' => '1.0.0']]
        );

        // Create a properly formatted session that matches the MCP server format
        $this->validSessionId = '2024-11-05_' . bin2hex(random_bytes(16));
        $this->storage->storeSession(
            $this->validSessionId,
            [
                'protocol_version' => '2024-11-05',
                'agency_id' => 1,
                'user_id' => 1,
                'created_at' => time()
            ],
            3600
        );
    }

    public function testJsonRpcVersionRequired(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32600);

        $data = ['method' => 'ping', 'id' => 1];
        $this->handler->processMessage($data, $this->validSessionId, [], new Response());
    }

    public function testRequestIdMustNotBeNull(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32600);

        $data = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => null
        ];
        $this->handler->processMessage($data, $this->validSessionId, [], new Response());
    }

    public function testRequestIdUniquenessTracking(): void
    {
        $data1 = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
            'id' => 'unique-id-123'
        ];

        $data2 = [
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 'unique-id-123'
        ];

        $response1 = $this->handler->processMessage($data1, $this->validSessionId, [], new Response());
        $this->assertEquals(200, $response1->getStatusCode());

        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32600);
        $this->handler->processMessage($data2, $this->validSessionId, [], new Response());
    }

    public function testNotificationHandling(): void
    {
        $notificationData = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized'
        ];

        $response = $this->handler->processMessage($notificationData, $this->validSessionId, [], new Response());

        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }

    public function testNotificationWithExplicitMethod(): void
    {
        $notificationData = [
            'jsonrpc' => '2.0',
            'method' => 'initialized'
        ];

        $response = $this->handler->processMessage($notificationData, $this->validSessionId, [], new Response());

        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }

    public function testErrorResponseFormat(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'nonexistent_method',
            'id' => 42
        ];

        $response = $this->handler->processMessage($data, $this->validSessionId, [], new Response());

        $this->assertEquals(202, $response->getStatusCode());

        // Verify error was queued
        $messages = $this->storage->getMessages($this->validSessionId);
        $this->assertCount(1, $messages);
        $errorMessage = $messages[0]['data'];
        $this->assertEquals('2.0', $errorMessage['jsonrpc']);
        $this->assertEquals(42, $errorMessage['id']);
        $this->assertArrayHasKey('error', $errorMessage);
        $this->assertEquals(-32601, $errorMessage['error']['code']);
    }

    public function testResponseIdCorrelationWithStringId(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
            'id' => 'string-id-456'
        ];

        $response = $this->handler->processMessage($data, $this->validSessionId, [], new Response());
        $responseData = json_decode((string) $response->getBody(), true);

        $this->assertEquals('2.0', $responseData['jsonrpc']);
        $this->assertEquals('string-id-456', $responseData['id']);
        $this->assertArrayHasKey('result', $responseData);
    }

    public function testResponseIdCorrelationWithNumericId(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
            'id' => 789
        ];

        $response = $this->handler->processMessage($data, $this->validSessionId, [], new Response());
        $responseData = json_decode((string) $response->getBody(), true);

        $this->assertEquals('2.0', $responseData['jsonrpc']);
        $this->assertEquals(789, $responseData['id']);
        $this->assertArrayHasKey('result', $responseData);
    }

    public function testStandardErrorCodes(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32600);

        $data = [
            'jsonrpc' => '2.0'
        ];

        $this->handler->processMessage($data, $this->validSessionId, [], new Response());
    }

    public function testMethodNotFoundError(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'does_not_exist',
            'id' => 1
        ];

        $response = $this->handler->processMessage($data, $this->validSessionId, [], new Response());

        $this->assertEquals(202, $response->getStatusCode());

        // Verify error was queued
        $messages = $this->storage->getMessages($this->validSessionId);
        $this->assertCount(1, $messages);
        $errorMessage = $messages[0]['data'];
        $this->assertEquals('2.0', $errorMessage['jsonrpc']);
        $this->assertEquals(1, $errorMessage['id']);
        $this->assertArrayHasKey('error', $errorMessage);
        $this->assertEquals(-32601, $errorMessage['error']['code']);
    }

    public function testInvalidParamsError(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionCode(-32602);

        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => 1
        ];

        $this->handler->processMessage($data, $this->validSessionId, [], new Response());
    }
}
