<?php

namespace Seolinkmap\Waasup\Tests\Integration;

use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tests\TestCase;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class DatabaseIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseStorage $storage;
    private MCPSaaSServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite driver is not available');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createDatabaseSchema();
        $this->seedTestData();

        $this->storage = new DatabaseStorage($this->pdo);

        $toolRegistry = new ToolRegistry();
        $toolRegistry->register(
            'test_tool',
            function ($params, $context) {
                return ['result' => 'success', 'params' => $params, 'context_id' => $context['context_id'] ?? null];
            },
            [
                'description' => 'Integration test tool',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string']
                    ]
                ]
            ]
        );

        $promptRegistry = new PromptRegistry();
        $resourceRegistry = new ResourceRegistry();

        $this->server = new MCPSaaSServer(
            $this->storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'server_info' => [
                    'name' => 'Integration Test Server',
                    'version' => '1.0.0-test'
                ],
                'sse' => [
                    'test_mode' => true
                ]
            ]
        );
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
                access_token VARCHAR(255) UNIQUE NOT NULL,
                refresh_token VARCHAR(255) DEFAULT NULL,
                token_type VARCHAR(50) NOT NULL DEFAULT 'Bearer',
                scope VARCHAR(500) DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                revoked INTEGER NOT NULL DEFAULT 0,
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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        "
        );
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
            INSERT INTO mcp_users (id, uuid, agency_id, name, email, active)
            VALUES (1, 'user-uuid-123', 1, 'Test User', 'test@example.com', 1)
        "
        );

        $this->pdo->exec(
            "
            INSERT INTO mcp_oauth_tokens (access_token, scope, expires_at, agency_id, user_id)
            VALUES ('integration-test-token', 'mcp:read mcp:write', datetime('now', '+1 hour'), 1, 1)
        "
        );
    }

    public function testCompleteInitializeFlow(): void
    {
        $context = [
            'context_data' => [
                'id' => 1,
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'Test Agency',
                'active' => 1,
                'context_type' => 'agency'
            ],
            'token_data' => [
                'access_token' => 'integration-test-token',
                'scope' => 'mcp:read mcp:write',
                'agency_id' => 1
            ],
            'context_id' => '550e8400-e29b-41d4-a716-446655440000',
            'base_url' => 'https://localhost:8080'
        ];

        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => [
                        'name' => 'Integration Test Client',
                        'version' => '1.0.0'
                    ]
                ],
                'id' => 1
                ]
            )
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $context);

        $response = $this->server->handle($initRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->assertJsonRpcSuccess($response, 1);
        $this->assertEquals('2024-11-05', $data['result']['protocolVersion']);
        $this->assertArrayHasKey('capabilities', $data['result']);
        $this->assertArrayHasKey('serverInfo', $data['result']);
    }

    public function testToolsListAndCallFlow(): void
    {
        $context = [
            'context_data' => [
                'id' => 1,
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'Test Agency',
                'active' => 1,
                'context_type' => 'agency'
            ],
            'token_data' => [
                'access_token' => 'integration-test-token',
                'scope' => 'mcp:read mcp:write',
                'agency_id' => 1
            ],
            'context_id' => '550e8400-e29b-41d4-a716-446655440000',
            'base_url' => 'https://localhost:8080'
        ];

        $initRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => [
                        'name' => 'Integration Test Client',
                        'version' => '1.0.0'
                    ]
                ],
                'id' => 1
                ]
            )
        );
        $initRequest = $initRequest->withAttribute('mcp_context', $context);

        $initResponse = $this->server->handle($initRequest, $this->createResponse());
        $this->assertEquals(200, $initResponse->getStatusCode());

        $sessionId = $initResponse->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        $toolsRequest = $this->createRequest(
            'POST',
            '/mcp/550e8400-e29b-41d4-a716-446655440000',
            [
                'Content-Type' => 'application/json',
                'Mcp-Session-Id' => $sessionId
            ],
            json_encode(
                [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 2
                ]
            )
        );
        $toolsRequest = $toolsRequest->withAttribute('mcp_context', $context);

        $response = $this->server->handle($toolsRequest, $this->createResponse());

        if ($response->getStatusCode() !== 202) {
            $body = (string) $response->getBody();
            echo "Unexpected response: " . $response->getStatusCode() . "\n";
            echo "Body: " . $body . "\n";
        }

        $this->assertEquals(202, $response->getStatusCode(), 'Tools list request should return 202 Accepted');

        $messages = $this->storage->getMessages($sessionId);
        $this->assertNotEmpty($messages);
        $this->assertEquals('2.0', $messages[0]['data']['jsonrpc']);
        $this->assertArrayHasKey('tools', $messages[0]['data']['result']);

        $toolCallRequest = $this->createRequest(
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
                'params' => [
                    'name' => 'test_tool',
                    'arguments' => ['message' => 'integration test']
                ],
                'id' => 3
                ]
            )
        );
        $toolCallRequest = $toolCallRequest->withAttribute('mcp_context', $context);

        $response = $this->server->handle($toolCallRequest, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode());

        $messages = $this->storage->getMessages($sessionId);
        $this->assertCount(2, $messages);

        $toolCallMessage = $messages[1];
        $this->assertEquals(3, $toolCallMessage['data']['id']);
        $this->assertArrayHasKey('content', $toolCallMessage['data']['result']);
    }

    public function testStoragePersistenceAcrossRequests(): void
    {

        $sessionData = ['user_id' => 1, 'agency_id' => 1, 'timestamp' => time()];
        $this->storage->storeSession('persistent-session', $sessionData);

        $this->storage->storeMessage(
            'persistent-session',
            [
                'jsonrpc' => '2.0',
                'result' => ['test' => 'message1'],
                'id' => 1
            ]
        );

        $this->storage->storeMessage(
            'persistent-session',
            [
                'jsonrpc' => '2.0',
                'result' => ['test' => 'message2'],
                'id' => 2
            ]
        );

        $retrievedSession = $this->storage->getSession('persistent-session');
        $this->assertEquals($sessionData, $retrievedSession);

        $messages = $this->storage->getMessages('persistent-session');
        $this->assertCount(2, $messages);
        $this->assertEquals('message1', $messages[0]['data']['result']['test']);
        $this->assertEquals('message2', $messages[1]['data']['result']['test']);
    }

    public function testSlimMCPProviderIntegration(): void
    {
        $config = [
            'server_info' => [
                'name' => 'Integration Test MCP Server',
                'version' => '1.0.0-integration'
            ],
            'auth' => [
                'context_types' => ['agency'],
                'base_url' => 'https://localhost:8080'
            ]
        ];

        $toolRegistry = new ToolRegistry();
        $toolRegistry->register(
            'integration_tool',
            function ($params) {
                return ['integration' => true, 'received' => $params];
            }
        );

        $promptRegistry = new PromptRegistry();
        $resourceRegistry = new ResourceRegistry();

        $provider = new SlimMCPProvider(
            $this->storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $this->responseFactory,
            $this->streamFactory,
            $config
        );

        $authRequest = $this->createRequest('GET', '/.well-known/oauth-authorization-server');
        $authResponse = $provider->handleAuthDiscovery($authRequest, $this->createResponse());

        $this->assertEquals(200, $authResponse->getStatusCode());
        $this->assertEquals('application/json', $authResponse->getHeaderLine('Content-Type'));

        $authData = json_decode((string) $authResponse->getBody(), true);
        $this->assertArrayHasKey('authorization_endpoint', $authData);
        $this->assertArrayHasKey('token_endpoint', $authData);
    }

    public function testDatabaseCleanupIntegration(): void
    {

        $this->storage->storeMessage('current-session', ['current' => 'message']);
        $this->storage->storeSession('current-session', ['current' => 'session'], 3600);

        $this->pdo->exec(
            "
            INSERT INTO mcp_messages (session_id, message_data, created_at)
            VALUES ('old-session', '{\"old\": \"message\"}', datetime('now', '-2 hours'))
        "
        );

        $this->pdo->exec(
            "
            INSERT INTO mcp_sessions (session_id, session_data, expires_at)
            VALUES ('expired-session', '{\"expired\": \"session\"}', datetime('now', '-1 hour'))
        "
        );

        $cleaned = $this->storage->cleanup();
        $this->assertGreaterThanOrEqual(0, $cleaned);

        $currentMessages = $this->storage->getMessages('current-session');
        $this->assertNotEmpty($currentMessages);

        $currentSession = $this->storage->getSession('current-session');
        $this->assertNotNull($currentSession);

        $expiredSession = $this->storage->getSession('expired-session');
        $this->assertNull($expiredSession);
    }
}
