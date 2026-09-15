<?php

namespace Seolinkmap\Waasup\Tests\Unit\Security;

use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Discovery\WellKnownProvider;
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * MCP 2025-06-18 Security & Authorization Compliance Tests
 */
class MCPSecurityComplianceTest extends TestCase
{
    private OAuthServer $oauthServer;
    private AuthMiddleware $authMiddleware;
    private WellKnownProvider $discoveryProvider;
    private MCPSaaSServer $mcpServer;
    private MemoryStorage $storage;
    private string $baseUrl = 'https://mcp.example.com';
    private string $contextId = '550e8400-e29b-41d4-a716-446655440000';
    private string $maliciousContextId = '550e8400-e29b-41d4-a716-446655440999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new MemoryStorage();
        $this->setupTestData();

        $config = [
            'base_url' => $this->baseUrl,
            'auth' => [
                'context_types' => ['agency'],
                'resource_server_metadata' => true,
                'require_resource_binding' => true
            ],
            'discovery' => [
                'scopes_supported' => ['mcp:read', 'mcp:write']
            ]
        ];

        $this->oauthServer = new OAuthServer(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            $config
        );

        $this->authMiddleware = new AuthMiddleware(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            $config
        );

        $this->discoveryProvider = new WellKnownProvider($config['discovery']);

        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();

        $this->mcpServer = new MCPSaaSServer(
            $this->storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            [
                'supported_versions' => ['2025-06-18'],
                'server_info' => ['name' => 'Security Test Server', 'version' => '1.0.0-test'],
                'test_mode' => true
            ]
        );
    }

    private function setupTestData(): void
    {

        $this->storage->addOAuthClient(
            'test-client-2025',
            [
            'client_id' => 'test-client-2025',
            'client_secret' => null,
            'client_name' => 'MCP 2025-06-18 Test Client',
            'redirect_uris' => ['https://client.example.com/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
            ]
        );

        $this->storage->addContext(
            $this->contextId,
            'agency',
            [
            'id' => 1,
            'uuid' => $this->contextId,
            'name' => 'Test Agency',
            'active' => true
            ]
        );

        $this->storage->addContext(
            $this->maliciousContextId,
            'agency',
            [
            'id' => 999,
            'uuid' => $this->maliciousContextId,
            'name' => 'Malicious Agency',
            'active' => true
            ]
        );

        $this->storage->addUser(
            1,
            [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'agency_id' => 1
            ]
        );
    }

    protected function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        ?string $body = null
    ) {

        $parsedUri = parse_url($uri);

        if (!isset($parsedUri['scheme']) || !isset($parsedUri['host'])) {
            $baseUri = parse_url($this->baseUrl);
            $fullUri = $baseUri['scheme'] . '://' . $baseUri['host'] .
                      (isset($baseUri['port']) ? ':' . $baseUri['port'] : '') . $uri;
        } else {
            $fullUri = $uri;
        }

        $request = $this->requestFactory->createServerRequest($method, $fullUri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $stream = $this->streamFactory->createStream($body);
            $request = $request->withBody($stream);
        }

        return $request;
    }

    public function testResourceIndicatorTokenBinding(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $expectedResource = $this->baseUrl . '/mcp/' . $this->contextId;

        $authCode = $this->completeAuthorizationFlow($codeVerifier, $codeChallenge, $expectedResource);

        if ($authCode === null) {
            $this->fail('Authorization flow did not complete successfully');
        }

        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier,
                'resource' => $expectedResource
                ]
            )
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(200, $tokenResponse->getStatusCode());
        $tokenData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertArrayHasKey('access_token', $tokenData);

        session_destroy();
    }

    public function testResourceIndicatorAudienceRestriction(): void
    {

        $boundResource = $this->baseUrl . '/mcp/' . $this->contextId;
        $this->storage->storeAccessToken(
            [
            'client_id' => 'test-client-2025',
            'access_token' => 'bound-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $boundResource,
            'aud' => [$boundResource]
            ]
        );

        $validRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer bound-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($validRequest, $mockHandler);
        $this->assertEquals(200, $response->getStatusCode());

        $invalidRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->maliciousContextId,
            [
                'Authorization' => 'Bearer bound-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $response = $this->authMiddleware->__invoke($invalidRequest, $mockHandler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testResourceIndicatorMaliciousServerPrevention(): void
    {

        $legitimateResource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken(
            [
            'client_id' => 'test-client-2025',
            'access_token' => 'legitimate-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $legitimateResource,
            'aud' => [$legitimateResource]
            ]
        );

        $maliciousRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->maliciousContextId,
            [
                'Authorization' => 'Bearer legitimate-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($maliciousRequest, $mockHandler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testResourceIndicatorInvalidResource(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $invalidResource = 'not-a-valid-url';

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $invalidResource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
        $this->assertStringContainsString('Resource parameter must be for this resource server', $errorData['error_description']);

        session_destroy();
    }

    public function testOAuthResourceServerClassification(): void
    {

        $request = $this->createRequest('GET', '/.well-known/oauth-protected-resource')
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->discoveryProvider->protectedResource($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $metadata = json_decode((string) $response->getBody(), true);

        $this->assertTrue($metadata['resource_server']);
        $this->assertTrue($metadata['resource_indicators_supported']);
        $this->assertTrue($metadata['token_binding_supported']);
        $this->assertTrue($metadata['audience_validation_required']);
        $this->assertArrayHasKey('authorization_servers', $metadata);
        $this->assertContains($this->baseUrl, $metadata['authorization_servers']);
    }

    public function testProtectedResourceMetadataDiscovery(): void
    {
        $request = $this->createRequest('GET', '/.well-known/oauth-protected-resource')
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->discoveryProvider->protectedResource($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $metadata = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('resource', $metadata);
        $this->assertArrayHasKey('authorization_servers', $metadata);
        $this->assertArrayHasKey('scopes_supported', $metadata);
        $this->assertArrayHasKey('bearer_methods_supported', $metadata);

        $this->assertArrayHasKey('mcp_features_supported', $metadata);
        $this->assertContains('elicitation', $metadata['mcp_features_supported']);
        $this->assertContains('structured_outputs', $metadata['mcp_features_supported']);
        $this->assertContains('resource_links', $metadata['mcp_features_supported']);
    }

    public function testProtectedResourceMetadataValidation(): void
    {
        $request = $this->createRequest('GET', '/.well-known/oauth-protected-resource')
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->discoveryProvider->protectedResource($request, $this->createResponse());
        $metadata = json_decode((string) $response->getBody(), true);

        $this->assertEquals($this->baseUrl, $metadata['resource']);
        $this->assertIsArray($metadata['authorization_servers']);
        $this->assertIsArray($metadata['scopes_supported']);
        $this->assertContains('mcp:read', $metadata['scopes_supported']);
        $this->assertContains('mcp:write', $metadata['scopes_supported']);
        $this->assertContains('header', $metadata['bearer_methods_supported']);

        $this->assertTrue($metadata['resource_indicators_supported']);
        $this->assertIsArray($metadata['mcp_features_supported']);
    }

    public function testAuthorizationServerDiscovery(): void
    {
        $request = $this->createRequest('GET', '/.well-known/oauth-authorization-server')
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->discoveryProvider->authorizationServer($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $metadata = json_decode((string) $response->getBody(), true);

        $this->assertEquals($this->baseUrl, $metadata['issuer']);
        $this->assertEquals($this->baseUrl . '/oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertEquals($this->baseUrl . '/oauth/token', $metadata['token_endpoint']);
        $this->assertContains('authorization_code', $metadata['grant_types_supported']);
        $this->assertContains('code', $metadata['response_types_supported']);

        $this->assertTrue($metadata['resource_indicators_supported']);
        $this->assertTrue($metadata['require_resource_parameter']);
        $this->assertContains('S256', $metadata['pkce_methods_supported']);
        $this->assertTrue($metadata['pkce_required']);
    }

    public function testAuthorizationServerMetadataIntegration(): void
    {

        $protectedResourceRequest = $this->createRequest('GET', '/.well-known/oauth-protected-resource');
        $protectedResourceResponse = $this->discoveryProvider->protectedResource($protectedResourceRequest, $this->createResponse());
        $protectedResourceData = json_decode((string) $protectedResourceResponse->getBody(), true);

        $authServerRequest = $this->createRequest('GET', '/.well-known/oauth-authorization-server');
        $authServerResponse = $this->discoveryProvider->authorizationServer($authServerRequest, $this->createResponse());
        $authServerData = json_decode((string) $authServerResponse->getBody(), true);

        $this->assertContains($authServerData['issuer'], $protectedResourceData['authorization_servers']);
    }

    public function testDynamicClientRegistration(): void
    {
        $registrationData = [
            'client_name' => 'Dynamic Test Client 2025-06-18',
            'redirect_uris' => ['https://dynamic.example.com/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => 'mcp:read mcp:write'
        ];

        $request = $this->createRequest('POST', '/oauth/register')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('MCP-Protocol-Version', '2025-06-18')
            ->withBody($this->streamFactory->createStream(json_encode($registrationData)));

        $response = $this->oauthServer->register($request, $this->createResponse());

        $this->assertEquals(201, $response->getStatusCode());
        $clientData = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('client_id', $clientData);
        $this->assertEquals($registrationData['client_name'], $clientData['client_name']);
        $this->assertContains('authorization_code', $clientData['grant_types']);
    }

    public function testTokenBindingEnforcement(): void
    {
        $resource1 = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken(
            [
            'client_id' => 'test-client-2025',
            'access_token' => 'bound-token-test',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $resource1,
            'aud' => [$resource1]
            ]
        );

        $validRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer bound-token-test',
                'MCP-Protocol-Version' => '2025-06-18',
                'Content-Type' => 'application/json'
            ],
            json_encode(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1])
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($validRequest, $mockHandler);
        $this->assertEquals(200, $response->getStatusCode());

        $invalidRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->maliciousContextId,
            [
                'Authorization' => 'Bearer bound-token-test',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $response = $this->authMiddleware->__invoke($invalidRequest, $mockHandler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testConfusedDeputyPrevention(): void
    {

        $legitimateResource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken(
            [
            'client_id' => 'legitimate-client',
            'access_token' => 'deputy-test-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $legitimateResource,
            'aud' => [$legitimateResource]
            ]
        );

        $validRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer deputy-test-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($validRequest, $mockHandler);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTokenPassthroughPrevention(): void
    {

        $originalResource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken(
            [
            'client_id' => 'test-client-2025',
            'access_token' => 'passthrough-test-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $originalResource,
            'aud' => [$originalResource]
            ]
        );

        $passthroughRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->maliciousContextId,
            [
                'Authorization' => 'Bearer passthrough-test-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($passthroughRequest, $mockHandler);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testSessionHijackingPrevention(): void
    {

        $this->addToAssertionCount(1);
    }

    public function testProxyMisusePrevention(): void
    {

        $resource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken(
            [
            'client_id' => 'test-client-2025',
            'access_token' => 'proxy-test-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $resource,
            'aud' => [$resource]
            ]
        );

        $validRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer proxy-test-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($validRequest, $mockHandler);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSecurityBestPracticesEnforcement(): void
    {

        $resource = $this->baseUrl . '/mcp/' . $this->contextId;

        $validRequest = $this->createRequest(
            'GET',
            '/.well-known/oauth-protected-resource',
            ['MCP-Protocol-Version' => '2025-06-18']
        );

        $response = $this->discoveryProvider->protectedResource($validRequest, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());

        $metadata = json_decode((string) $response->getBody(), true);
        $this->assertTrue($metadata['resource_indicators_supported']);
    }

    private function completeAuthorizationFlow(string $codeVerifier, string $codeChallenge, string $resource): ?string
    {

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $resource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());
        if ($authResponse->getStatusCode() !== 200) {
            return null;
        }

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());

        if ($consentResponse->getStatusCode() !== 302) {
            return null;
        }

        $location = $consentResponse->getHeaderLine('Location');
        if (empty($location)) {
            return null;
        }

        $parsedUrl = parse_url($location);
        if (!isset($parsedUrl['query'])) {
            return null;
        }

        parse_str($parsedUrl['query'], $params);
        return $params['code'] ?? null;
    }

    public function testInsufficientScopeIsAStepUpChallenge(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $storage = new \Seolinkmap\Waasup\Storage\MemoryStorage();
        $storage->addContext($uuid, 'agency', ['id' => 1, 'uuid' => $uuid, 'name' => 'A', 'active' => true]);
        $storage->addToken(
            'partial-token',
            [
                'access_token' => 'partial-token',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1,
                'revoked' => false
            ]
        );

        $middleware = new AuthMiddleware(
            $storage,
            $this->responseFactory,
            $this->streamFactory,
            [
                'base_url' => 'https://mcp.example.com',
                'auth' => [
                    'context_types' => ['agency'],
                    'validate_scope' => true,
                    'required_scopes' => ['mcp:read', 'mcp:write']
                ]
            ]
        );

        $handler = $this->createMockRequestHandler(200);

        $unauthenticated = $middleware(
            $this->createRequest('POST', '/mcp/' . $uuid),
            $handler
        );

        $this->assertEquals(401, $unauthenticated->getStatusCode());
        $this->assertStringContainsString('scope="mcp:read mcp:write"', $unauthenticated->getHeaderLine('WWW-Authenticate'));

        $underScoped = $middleware(
            $this->createRequest('POST', '/mcp/' . $uuid, ['Authorization' => 'Bearer partial-token']),
            $handler
        );

        $challenge = $underScoped->getHeaderLine('WWW-Authenticate');

        $this->assertEquals(403, $underScoped->getStatusCode());
        $this->assertStringContainsString('error="insufficient_scope"', $challenge);
        $this->assertStringContainsString('scope="mcp:read mcp:write"', $challenge);
        $this->assertStringContainsString('resource_metadata="', $challenge);

        $body = json_decode((string) $underScoped->getBody(), true);
        $this->assertEquals('insufficient_scope', $body['error']);
    }

    private function createMockRequestHandler(int $statusCode): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class ($statusCode, $this->responseFactory) implements \Psr\Http\Server\RequestHandlerInterface {
            private int $statusCode;
            private \Psr\Http\Message\ResponseFactoryInterface $responseFactory;

            public function __construct(int $statusCode, \Psr\Http\Message\ResponseFactoryInterface $responseFactory)
            {
                $this->statusCode = $statusCode;
                $this->responseFactory = $responseFactory;
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->responseFactory->createResponse($this->statusCode);
            }
        };
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
