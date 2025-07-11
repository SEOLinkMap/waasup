<?php

namespace Seolinkmap\Waasup\Tests\Unit\Storage;

use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

class MemoryStorageTest extends TestCase
{
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new MemoryStorage();
    }

    public function testStoreAndGetMessage(): void
    {
        $messageData = ['jsonrpc' => '2.0', 'result' => 'test', 'id' => 1];
        $context = ['test' => 'context'];

        $result = $this->storage->storeMessage('session123', $messageData, $context);
        $this->assertTrue($result);

        $messages = $this->storage->getMessages('session123');
        $this->assertCount(1, $messages);
        $this->assertEquals($messageData, $messages[0]['data']);
        $this->assertEquals($context, $messages[0]['context']);
        $this->assertArrayHasKey('id', $messages[0]);
        $this->assertArrayHasKey('created_at', $messages[0]);
    }

    public function testGetMessagesEmptySession(): void
    {
        $messages = $this->storage->getMessages('nonexistent');
        $this->assertEmpty($messages);
    }

    public function testDeleteMessage(): void
    {
        // Store a message
        $this->storage->storeMessage('session123', ['test' => 'data']);
        $messages = $this->storage->getMessages('session123');
        $messageId = $messages[0]['id'];

        // Delete it
        $result = $this->storage->deleteMessage($messageId);
        $this->assertTrue($result);

        // Verify it's gone
        $messagesAfter = $this->storage->getMessages('session123');
        $this->assertEmpty($messagesAfter);
    }

    public function testDeleteNonexistentMessage(): void
    {
        $result = $this->storage->deleteMessage('nonexistent');
        $this->assertFalse($result);
    }

    public function testValidateToken(): void
    {
        // Add a valid token
        $tokenData = [
            'access_token' => 'valid-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'revoked' => false,
            'agency_id' => 1
        ];
        $this->storage->addToken('valid-token', $tokenData);

        $result = $this->storage->validateToken('valid-token');
        $this->assertEquals($tokenData, $result);
    }

    public function testValidateExpiredToken(): void
    {
        // Add an expired token
        $tokenData = [
            'access_token' => 'expired-token',
            'scope' => 'mcp:read',
            'expires_at' => time() - 3600,
            'revoked' => false,
            'agency_id' => 1
        ];
        $this->storage->addToken('expired-token', $tokenData);

        $result = $this->storage->validateToken('expired-token');
        $this->assertNull($result);
    }

    public function testValidateRevokedToken(): void
    {
        // Add a revoked token
        $tokenData = [
            'access_token' => 'revoked-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'revoked' => true,
            'agency_id' => 1
        ];
        $this->storage->addToken('revoked-token', $tokenData);

        $result = $this->storage->validateToken('revoked-token');
        $this->assertNull($result);
    }

    public function testValidateNonexistentToken(): void
    {
        $result = $this->storage->validateToken('nonexistent-token');
        $this->assertNull($result);
    }

    public function testGetContextData(): void
    {
        $agencyData = [
            'id' => 1,
            'uuid' => 'agency-uuid',
            'name' => 'Test Agency',
            'active' => true
        ];
        $this->storage->addContext('agency-uuid', 'agency', $agencyData);

        $result = $this->storage->getContextData('agency-uuid', 'agency');
        $this->assertEquals($agencyData, $result);
    }

    public function testGetUserContextData(): void
    {
        $userData = [
            'id' => 1,
            'uuid' => 'user-uuid',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'agency_id' => 1,
            'active' => true
        ];
        $this->storage->addContext('user-uuid', 'user', $userData);

        $result = $this->storage->getContextData('user-uuid', 'user');
        $this->assertEquals($userData, $result);
    }

    public function testGetNonexistentContextData(): void
    {
        $result = $this->storage->getContextData('nonexistent', 'agency');
        $this->assertNull($result);
    }

    public function testStoreAndGetSession(): void
    {
        $sessionData = ['user_id' => 123, 'agency_id' => 456];

        $result = $this->storage->storeSession('session123', $sessionData, 3600);
        $this->assertTrue($result);

        $retrieved = $this->storage->getSession('session123');
        $this->assertEquals($sessionData, $retrieved);
    }

    public function testGetExpiredSession(): void
    {
        $sessionData = ['user_id' => 123];

        // Store with very short TTL
        $this->storage->storeSession('session123', $sessionData, -1);

        $retrieved = $this->storage->getSession('session123');
        $this->assertNull($retrieved);
    }

    public function testGetNonexistentSession(): void
    {
        $result = $this->storage->getSession('nonexistent');
        $this->assertNull($result);
    }

    public function testCleanup(): void
    {
        $now = time();

        // Add some expired data
        $this->storage->storeMessage('session1', ['old' => 'message']);
        $this->storage->storeSession('session2', ['old' => 'session'], -1);

        // Add some current data
        $this->storage->storeMessage('session3', ['new' => 'message']);
        $this->storage->storeSession('session4', ['new' => 'session'], 3600);

        // Wait a moment to ensure timestamps differ
        sleep(1);

        $cleaned = $this->storage->cleanup();

        // Should have cleaned at least the expired session
        $this->assertGreaterThan(0, $cleaned);

        // Current data should still exist
        $this->assertNotEmpty($this->storage->getMessages('session3'));
        $this->assertNotNull($this->storage->getSession('session4'));

        // Expired session should be gone
        $this->assertNull($this->storage->getSession('session2'));
    }

    public function testMultipleMessagesInSession(): void
    {
        $this->storage->storeMessage('session123', ['message' => 1]);
        $this->storage->storeMessage('session123', ['message' => 2]);
        $this->storage->storeMessage('session123', ['message' => 3]);

        $messages = $this->storage->getMessages('session123');
        $this->assertCount(3, $messages);

        // Messages should be in order
        $this->assertEquals(1, $messages[0]['data']['message']);
        $this->assertEquals(2, $messages[1]['data']['message']);
        $this->assertEquals(3, $messages[2]['data']['message']);
    }

    public function testHelperMethods(): void
    {
        // Test the helper methods exist and work
        $tokenData = ['test' => 'token', 'expires_at' => time() + 3600, 'revoked' => false];
        $contextData = ['test' => 'context'];

        $this->storage->addToken('test-token', $tokenData);
        $this->storage->addContext('test-id', 'test-type', $contextData);

        $this->assertEquals($tokenData, $this->storage->validateToken('test-token'));
        $this->assertEquals($contextData, $this->storage->getContextData('test-id', 'test-type'));
    }

    public function testSessionUpdate(): void
    {
        $sessionData1 = ['user_id' => 123];
        $sessionData2 = ['user_id' => 456, 'updated' => true];

        // Store initial session
        $this->storage->storeSession('session123', $sessionData1, 3600);
        $retrieved1 = $this->storage->getSession('session123');
        $this->assertEquals($sessionData1, $retrieved1);

        // Update session
        $this->storage->storeSession('session123', $sessionData2, 3600);
        $retrieved2 = $this->storage->getSession('session123');
        $this->assertEquals($sessionData2, $retrieved2);
    }

    public function testTokenValidationWithContext(): void
    {
        $tokenData = [
            'access_token' => 'context-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'revoked' => false,
            'agency_id' => 1
        ];
        $this->storage->addToken('context-token', $tokenData);

        $context = ['agency_id' => 1];
        $result = $this->storage->validateToken('context-token', $context);
        $this->assertEquals($tokenData, $result);
    }

    public function testMessageContextStorage(): void
    {
        $messageData = ['jsonrpc' => '2.0', 'method' => 'test'];
        $context = ['agency_id' => 1, 'user_id' => 123];

        $this->storage->storeMessage('session123', $messageData, $context);

        $messages = $this->storage->getMessages('session123', $context);
        $this->assertCount(1, $messages);
        $this->assertEquals($context, $messages[0]['context']);
    }

    public function testDeleteMessageFromMultipleMessages(): void
    {
        // Store multiple messages
        $this->storage->storeMessage('session123', ['message' => 1]);
        $this->storage->storeMessage('session123', ['message' => 2]);
        $this->storage->storeMessage('session123', ['message' => 3]);

        $messages = $this->storage->getMessages('session123');
        $this->assertCount(3, $messages);

        // Delete middle message
        $middleMessageId = $messages[1]['id'];
        $result = $this->storage->deleteMessage($middleMessageId);
        $this->assertTrue($result);

        // Verify only 2 messages remain and array is re-indexed
        $remainingMessages = $this->storage->getMessages('session123');
        $this->assertCount(2, $remainingMessages);
        $this->assertEquals(1, $remainingMessages[0]['data']['message']);
        $this->assertEquals(3, $remainingMessages[1]['data']['message']);
    }
}
