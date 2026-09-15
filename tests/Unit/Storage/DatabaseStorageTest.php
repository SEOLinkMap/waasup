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

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite driver is not available');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createDatabaseSchema();
        $this->storage = new DatabaseStorage($this->pdo);
    }

    private function createDatabaseSchema(): void
    {

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
                resource VARCHAR(255) DEFAULT NULL,
                aud TEXT DEFAULT NULL,
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

        $this->seedTestData();
    }

    private function seedTestData(): void
    {

        $this->pdo->exec(
            "
            INSERT INTO mcp_agencies (id, uuid, name, active)
            VALUES (1, '550e8400-e29b-41d4-a716-446655440000', 'Test Agency', 1)
        "
        );

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

        $this->pdo->exec(
            "
            INSERT INTO mcp_oauth_tokens (access_token, token_type, scope, expires_at, agency_id)
            VALUES ('expired-token', 'Bearer', 'mcp:read', datetime('now', '-1 hour'), 1)
        "
        );

        $result = $this->storage->validateToken('expired-token');
        $this->assertNull($result);
    }

    public function testStoreAccessTokenPersistsResourceBinding(): void
    {
        $storage = $this->createStorage('https://mcp.example.com/mcp/agency-uuid');
        $expiresAt = time() + 3600;

        $result = $storage->storeAccessToken(
            [
                'client_id' => 'test-client',
                'access_token' => 'stored-token',
                'refresh_token' => 'stored-refresh',
                'scope' => 'mcp:read mcp:write',
                'expires_at' => $expiresAt,
                'agency_id' => 1,
                'user_id' => 1
            ]
        );

        $this->assertTrue($result);

        $stored = $storage->validateToken('stored-token');

        $this->assertNotNull($stored);
        $this->assertEquals('stored-refresh', $stored['refresh_token']);
        $this->assertEquals('mcp:read mcp:write', $stored['scope']);
        $this->assertEquals('Bearer', $stored['token_type']);
        $this->assertEquals(date('Y-m-d H:i:s', $expiresAt), $stored['expires_at']);
        $this->assertEquals(0, $stored['revoked']);
        $this->assertEquals('https://mcp.example.com/mcp/agency-uuid', $stored['resource']);
        $this->assertEquals(['https://mcp.example.com/mcp/agency-uuid'], $stored['aud']);
        $this->assertEqualsWithDelta(time(), strtotime($stored['created_at']), 5);
    }

    public function testStoreAccessTokenWithoutConfiguredStorage(): void
    {
        $storage = new DatabaseStorage($this->pdo);
        $raised = [];

        set_error_handler(
            function (int $severity, string $message) use (&$raised): bool {
                $raised[] = $message;
                return true;
            }
        );

        try {
            $result = $storage->storeAccessToken(
                [
                    'client_id' => 'test-client',
                    'access_token' => 'defaulted-token',
                    'refresh_token' => 'defaulted-refresh',
                    'scope' => 'mcp:read',
                    'expires_at' => time() + 3600,
                    'agency_id' => 1,
                    'user_id' => 1
                ]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'Storing a token raised PHP errors');
        $this->assertTrue($result);

        $stored = $storage->validateToken('defaulted-token');

        $this->assertNotNull($stored);
        $this->assertEquals('defaulted-refresh', $stored['refresh_token']);
        $this->assertNull($stored['resource'] ?? null);
    }

    public function testStoreAccessTokenKeepsExplicitAudience(): void
    {
        $storage = $this->createStorage('https://mcp.example.com/mcp/agency-uuid');

        $storage->storeAccessToken(
            [
                'client_id' => 'test-client',
                'access_token' => 'audience-token',
                'refresh_token' => 'audience-refresh',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1,
                'user_id' => 1,
                'aud' => ['https://mcp.example.com/mcp/agency-uuid', 'https://mcp.example.com/mcp/other']
            ]
        );

        $stored = $storage->validateToken('audience-token');

        $this->assertNotNull($stored);
        $this->assertEquals(
            ['https://mcp.example.com/mcp/agency-uuid', 'https://mcp.example.com/mcp/other'],
            $stored['aud']
        );
    }

    public function testStoreAccessTokenWithoutBaseUrlHasNoResourceBinding(): void
    {
        $storage = $this->createStorage(null);

        $storage->storeAccessToken(
            [
                'client_id' => 'test-client',
                'access_token' => 'unbound-token',
                'refresh_token' => 'unbound-refresh',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1,
                'user_id' => 1,
                'aud' => ['https://mcp.example.com/mcp/agency-uuid']
            ]
        );

        $stored = $storage->validateToken('unbound-token');

        $this->assertNotNull($stored);
        $this->assertNull($stored['resource'] ?? null);
        $this->assertNull($stored['aud'] ?? null);
    }

    public function testStoreAccessTokenRejectsDuplicateToken(): void
    {
        $storage = $this->createStorage('https://mcp.example.com/mcp/agency-uuid');
        $tokenData = [
            'client_id' => 'test-client',
            'access_token' => 'duplicate-token',
            'refresh_token' => 'duplicate-refresh',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1
        ];

        $this->assertTrue($storage->storeAccessToken($tokenData));

        $tokenData['refresh_token'] = 'second-refresh';
        $this->assertFalse($storage->storeAccessToken($tokenData));

        $stored = $storage->validateToken('duplicate-token');
        $this->assertEquals('duplicate-refresh', $stored['refresh_token']);
    }

    public function testStoredAccessTokenIsReachableByRefreshTokenUntilRevoked(): void
    {
        $storage = $this->createStorage('https://mcp.example.com/mcp/agency-uuid');

        $storage->storeAccessToken(
            [
                'client_id' => 'test-client',
                'access_token' => 'refreshable-token',
                'refresh_token' => 'refreshable-refresh',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1,
                'user_id' => 1
            ]
        );

        $byRefresh = $storage->getTokenByRefreshToken('refreshable-refresh', 'test-client');

        $this->assertNotNull($byRefresh);
        $this->assertEquals('refreshable-token', $byRefresh['access_token']);
        $this->assertNull($storage->getTokenByRefreshToken('refreshable-refresh', 'other-client'));

        $this->assertTrue($storage->revokeToken('refreshable-refresh'));
        $this->assertNull($storage->getTokenByRefreshToken('refreshable-refresh', 'test-client'));
        $this->assertNull($storage->validateToken('refreshable-token'));
    }

    private function createStorage(?string $baseUrl): DatabaseStorage
    {
        return new DatabaseStorage($this->pdo, ['base_url' => $baseUrl]);
    }

    public function testTouchAccessTokenExtendsExpiry(): void
    {
        $newExpiry = time() + 7200;

        $this->assertTrue($this->storage->touchAccessToken('test-token', $newExpiry));

        $stored = $this->storage->validateToken('test-token');
        $this->assertNotNull($stored);
        $this->assertEquals(date('Y-m-d H:i:s', $newExpiry), $stored['expires_at']);
        $this->assertEquals('mcp:read mcp:write', $stored['scope']);
    }

    public function testTouchAccessTokenRevivesNothingRevoked(): void
    {
        $this->pdo->exec(
            "
            INSERT INTO mcp_oauth_tokens (access_token, token_type, scope, expires_at, revoked, agency_id)
            VALUES ('revoked-token', 'Bearer', 'mcp:read', datetime('now', '+1 hour'), 1, 1)
        "
        );

        $this->assertFalse($this->storage->touchAccessToken('revoked-token', time() + 7200));
        $this->assertNull($this->storage->validateToken('revoked-token'));
    }

    public function testTouchAccessTokenIgnoresAuthorizationCodes(): void
    {
        $storage = $this->createStorage('https://mcp.example.com/mcp/agency-uuid');
        $expiresAt = time() + 300;

        $storage->storeAuthorizationCode(
            'auth-code-value',
            [
                'client_id' => 'test-client',
                'scope' => 'mcp:read',
                'expires_at' => $expiresAt,
                'code_challenge' => 'challenge',
                'code_challenge_method' => 'S256',
                'agency_id' => 1,
                'user_id' => 1
            ]
        );

        $this->assertFalse($storage->touchAccessToken('auth-code-value', time() + 7200));

        $code = $storage->getAuthorizationCode('auth-code-value', 'test-client');
        $this->assertNotNull($code);
        $this->assertEquals(date('Y-m-d H:i:s', $expiresAt), $code['expires_at']);
        $this->assertEquals('challenge', $code['code_challenge']);
        $this->assertEquals('https://mcp.example.com/mcp/agency-uuid', $code['resource']);
    }

    public function testTouchAccessTokenRejectsUnknownToken(): void
    {
        $this->assertFalse($this->storage->touchAccessToken('no-such-token', time() + 7200));
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

        $this->storage->storeMessage('session1', ['test' => 'message']);

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
