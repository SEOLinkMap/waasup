<?php

namespace Seolinkmap\Waasup\Tests\Unit\Storage;

use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tests\TestCase;

class DatabaseStorageTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory SQLite database for testing
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createDatabaseSchema();
        $this->storage = new DatabaseStorage($this->pdo);
    }

    private function createDatabaseSchema(): void
    {
        // Create tables for testing
        $this->pdo->exec(
            "
            CREATE TABLE mcp_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64) NOT NULL,
                message_data TEXT NOT NULL,
                context_data TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );

        $this->pdo->exec(
            "
            CREATE TABLE mcp_sessions (
                session_id VARCHAR(64) PRIMARY KEY,
                session_data TEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );

        $this->pdo->exec(
            "
            CREATE TABLE mcp_oauth_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id VARCHAR(255) DEFAULT NULL,
                access_token VARCHAR(255) UNIQUE NOT NULL,
                refresh_token VARCHAR(255) DEFAULT NULL,
                token_type VARCHAR(50) NOT NULL DEFAULT 'Bearer',
                scope VARCHAR(500) DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                revoked INTEGER NOT NULL DEFAULT 0,
                code_challenge VARCHAR(255) DEFAULT NULL,
                code_challenge_method VARCHAR(10) DEFAULT NULL,
                agency_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );

        $this->pdo->exec(
            "
            CREATE TABLE mcp_agencies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );

        $this->pdo->exec(
            "
            CREATE TABLE mcp_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                agency_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );

        // Insert test data
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        // Insert test agency
        $this->pdo->exec(
            "
            INSERT INTO mcp_agencies (id, uuid, name, active)
            VALUES (1, '550e8400-e29b-41d4-a716-446655440000', 'Test Agency', 1)
        "
        );

        // Insert test token
        $this->pdo->exec(
            "
            INSERT INTO mcp_oauth_tokens (access_token, token_type, scope, expires_at, agency_id)
            VALUES ('test-token', 'Bearer', 'mcp:read mcp:write', datetime('now', '+1 hour'), 1)
        "
        );
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
    }

    public function testDeleteMessage(): void
    {
        $this->storage->storeMessage('session123', ['test' => 'data']);
        $messages = $this->storage->getMessages('session123');
        $messageId = $messages[0]['id'];

        $result = $this->storage->deleteMessage($messageId);
        $this->assertTrue($result);

        $messagesAfter = $this->storage->getMessages('session123');
        $this->assertEmpty($messagesAfter);
    }

    public function testValidateToken(): void
    {
        $result = $this->storage->validateToken('test-token');
        $this->assertNotNull($result);
        $this->assertEquals('test-token', $result['access_token']);
    }

    public function testValidateExpiredToken(): void
    {
        // Insert expired token
        $this->pdo->exec(
            "
            INSERT INTO mcp_oauth_tokens (access_token, token_type, scope, expires_at, agency_id)
            VALUES ('expired-token', 'Bearer', 'mcp:read', datetime('now', '-1 hour'), 1)
        "
        );

        $result = $this->storage->validateToken('expired-token');
        $this->assertNull($result);
    }

    public function testGetContextData(): void
    {
        $result = $this->storage->getContextData('550e8400-e29b-41d4-a716-446655440000', 'agency');
        $this->assertNotNull($result);
        $this->assertEquals('Test Agency', $result['name']);
    }

    public function testStoreAndGetSession(): void
    {
        $sessionData = ['user_id' => 123, 'agency_id' => 456];

        $result = $this->storage->storeSession('session123', $sessionData, 3600);
        $this->assertTrue($result);

        $retrieved = $this->storage->getSession('session123');
        $this->assertEquals($sessionData, $retrieved);
    }

    public function testCleanup(): void
    {
        // Add some test messages
        $this->storage->storeMessage('session1', ['test' => 'message']);

        // Add expired message directly
        $this->pdo->exec(
            "
            INSERT INTO mcp_messages (session_id, message_data, created_at)
            VALUES ('old-session', '{\"old\": \"message\"}', datetime('now', '-2 hours'))
        "
        );

        $cleaned = $this->storage->cleanup();
        $this->assertGreaterThanOrEqual(0, $cleaned);
    }
}
