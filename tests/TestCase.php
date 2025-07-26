<?php

namespace Seolinkmap\Waasup\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;

abstract class TestCase extends PHPUnitTestCase
{
    protected ResponseFactoryInterface $responseFactory;
    protected StreamFactoryInterface $streamFactory;
    protected ServerRequestFactoryInterface $requestFactory;
    protected UriFactoryInterface $uriFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();
        $this->requestFactory = new ServerRequestFactory();
        $this->uriFactory = new UriFactory();
    }

    /**
     * Generate session ID in the format used by the actual MCP server
     * Format: protocolVersion_hexstring (e.g., "2024-11-05_a1b2c3d4e5f6...")
     */
    protected function generateMcpSessionId(string $protocolVersion = '2024-11-05'): string
    {
        return $protocolVersion . '_' . bin2hex(random_bytes(16));
    }

    /**
     * Create a test storage with sample data and properly formatted sessions
     */
    protected function createTestStorage(): MemoryStorage
    {
        $storage = new MemoryStorage();

        // Add test agency
        $storage->addContext(
            '550e8400-e29b-41d4-a716-446655440000',
            'agency',
            [
            'id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Test Agency',
            'active' => true
            ]
        );

        // Add test user
        $storage->addContext(
            'user123',
            'user',
            [
            'id' => 1,
            'uuid' => 'user-uuid-123',
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'active' => true
            ]
        );

        // Add test token
        $storage->addToken(
            'test-valid-token',
            [
            'access_token' => 'test-valid-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'revoked' => false
            ]
        );

        // Add expired token
        $storage->addToken(
            'test-expired-token',
            [
            'access_token' => 'test-expired-token',
            'scope' => 'mcp:read',
            'expires_at' => time() - 3600,
            'agency_id' => 1,
            'revoked' => false
            ]
        );

        // Add test sessions using the ACTUAL MCP server format
        $this->setupTestSessions($storage);

        return $storage;
    }

    /**
     * Setup standard test sessions using the actual MCP server session format
     * Sessions are stored as: protocolVersion_sessionId with session data containing protocol_version
     */
    protected function setupTestSessions(MemoryStorage $storage): void
    {
        $protocols = ['2024-11-05', '2025-03-26', '2025-06-18'];
        $baseSessionNames = ['session1', 'session2', 'session3', 'test-session', 'session123'];

        foreach ($protocols as $index => $protocol) {
            // Create proper MCP session ID format
            $sessionId = $this->generateMcpSessionId($protocol);

            $sessionData = [
                'protocol_version' => $protocol,
                'agency_id' => 1,
                'user_id' => 1,
                'created_at' => time()
            ];

            $storage->storeSession($sessionId, $sessionData, 3600);

            // Also create sessions with predictable names for specific tests
            if ($index < count($baseSessionNames)) {
                $predictableSessionId = $protocol . '_' . hash('sha256', $baseSessionNames[$index]);
                $storage->storeSession($predictableSessionId, $sessionData, 3600);
            }
        }

        // Create specific well-known sessions for protocol compliance tests
        $wellKnownSessions = [
            'session1' => '2024-11-05_' . hash('sha256', 'session1'),
            'session123' => '2024-11-05_' . hash('sha256', 'session123'),
            'test-session' => '2024-11-05_' . hash('sha256', 'test-session')
        ];

        foreach ($wellKnownSessions as $alias => $fullSessionId) {
            $sessionData = [
                'protocol_version' => '2024-11-05',
                'agency_id' => 1,
                'user_id' => 1,
                'created_at' => time()
            ];
            $storage->storeSession($fullSessionId, $sessionData, 3600);
        }
    }

    /**
     * Create a session with specific protocol version using actual MCP server format
     */
    protected function createTestSession(MemoryStorage $storage, string $sessionId, string $protocolVersion = '2024-11-05'): string
    {
        // If sessionId doesn't already include protocol version, format it properly
        if (!str_contains($sessionId, '_')) {
            $sessionId = $protocolVersion . '_' . hash('sha256', $sessionId);
        }

        $sessionData = [
            'protocol_version' => $protocolVersion,
            'agency_id' => 1,
            'user_id' => 1,
            'created_at' => time()
        ];

        $storage->storeSession($sessionId, $sessionData, 3600);
        return $sessionId;
    }

    /**
     * Get a properly formatted session ID for testing
     * This returns a session ID that will work with the actual MCP server
     */
    protected function getTestSessionId(string $protocolVersion = '2024-11-05', string $baseName = 'test'): string
    {
        return $protocolVersion . '_' . hash('sha256', $baseName . time());
    }

    /**
     * Create a test tool registry with sample tools
     */
    protected function createTestToolRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry();

        // Add simple test tool
        $registry->register(
            'test_tool',
            function ($params, $context) {
                return [
                'success' => true,
                'params' => $params,
                'has_context' => !empty($context)
                ];
            },
            [
            'description' => 'Simple test tool',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string']
                ]
            ]
            ]
        );

        // Add tool with required parameters
        $registry->register(
            'required_params_tool',
            function ($params) {
                return ['value' => $params['required_param']];
            },
            [
            'description' => 'Tool with required parameters',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'required_param' => ['type' => 'string']
                ],
                'required' => ['required_param']
            ]
            ]
        );

        return $registry;
    }

    /**
     * Create a test prompt registry with sample prompts
     */
    protected function createTestPromptRegistry(): PromptRegistry
    {
        $registry = new PromptRegistry();

        // Add simple test prompt
        $registry->register(
            'test_prompt',
            function ($arguments, $context) {
                $topic = $arguments['topic'] ?? 'general';
                return [
                    'description' => "A prompt about {$topic}",
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Please provide information about {$topic}."
                            ]
                        ]
                    ]
                ];
            },
            [
                'description' => 'Simple test prompt',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string']
                    ]
                ]
            ]
        );

        return $registry;
    }

    /**
     * Create a test resource registry with sample resources
     */
    protected function createTestResourceRegistry(): ResourceRegistry
    {
        $registry = new ResourceRegistry();

        // Add simple test resource
        $registry->register(
            'test://resource',
            function ($uri, $context) {
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'text/plain',
                            'text' => 'This is a test resource'
                        ]
                    ]
                ];
            },
            [
                'name' => 'Test Resource',
                'description' => 'A simple test resource',
                'mimeType' => 'text/plain'
            ]
        );

        // Add test resource template
        $registry->registerTemplate(
            'test://{id}',
            function ($uri, $context) {
                $id = str_replace('test://', '', $uri);
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode(['id' => $id, 'data' => 'test data'])
                        ]
                    ]
                ];
            },
            [
                'name' => 'Test Template',
                'description' => 'A test resource template',
                'mimeType' => 'application/json'
            ]
        );

        return $registry;
    }

    /**
     * Create a mock PSR-7 request
     */
    protected function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        ?string $body = null
    ) {
        $request = $this->requestFactory->createServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $stream = $this->streamFactory->createStream($body);
            $request = $request->withBody($stream);
        }

        return $request;
    }

    /**
     * Create a mock PSR-7 response
     */
    protected function createResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->responseFactory->createResponse();
    }

    /**
     * Assert that a response contains valid JSON-RPC
     */
    protected function assertValidJsonRpc($response, ?int $expectedId = null): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertIsArray($data, 'Response body should be valid JSON');
        $this->assertEquals('2.0', $data['jsonrpc'] ?? null, 'Should have jsonrpc version 2.0');

        if ($expectedId !== null) {
            $this->assertEquals($expectedId, $data['id'], 'Should have correct request ID');
        }

        return $data;
    }

    /**
     * Assert that a response is a JSON-RPC error
     */
    protected function assertJsonRpcError($response, int $expectedCode, ?int $expectedId = null): array
    {
        $data = $this->assertValidJsonRpc($response, $expectedId);

        $this->assertArrayHasKey('error', $data, 'Response should contain error');
        $this->assertArrayHasKey('code', $data['error'], 'Error should have code');
        $this->assertArrayHasKey('message', $data['error'], 'Error should have message');
        $this->assertEquals($expectedCode, $data['error']['code'], 'Should have correct error code');

        return $data;
    }

    /**
     * Assert that a response is a JSON-RPC success
     */
    protected function assertJsonRpcSuccess($response, ?int $expectedId = null): array
    {
        $data = $this->assertValidJsonRpc($response, $expectedId);

        $this->assertArrayHasKey('result', $data, 'Response should contain result');
        $this->assertArrayNotHasKey('error', $data, 'Response should not contain error');

        return $data;
    }

    /**
     * Create test context data
     */
    protected function createTestContext(array $overrides = []): array
    {
        return array_merge(
            [
            'context_data' => [
                'id' => 1,
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'Test Agency',
                'active' => true,
                'context_type' => 'agency'
            ],
            'token_data' => [
                'access_token' => 'test-valid-token',
                'scope' => 'mcp:read mcp:write',
                'expires_at' => time() + 3600,
                'agency_id' => 1
            ],
            'context_id' => '550e8400-e29b-41d4-a716-446655440000',
            'base_url' => 'https://localhost:8080'
            ],
            $overrides
        );
    }
}
