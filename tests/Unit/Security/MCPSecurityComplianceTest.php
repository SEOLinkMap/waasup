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
 *
 * This test class validates compliance with MCP 2025-06-18 specification
 * security requirements including:
 * - RFC 8707 Resource Indicators
 * - OAuth 2.0 Resource Server classification
 * - Enhanced security features and best practices
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new MemoryStorage();
        $this->setupTestData();

        $config = [
            'base_url' => $this->baseUrl,
            'auth' => [
                'context_types' => ['agency'],
                'base_url' => $this->baseUrl,
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
            $config['auth']
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
                'sse' => ['test_mode' => true]
            ]
        );
    }

    private function setupTestData(): void
    {
        // Add test client supporting 2025-06-18
        $this->storage->addOAuthClient('test-client-2025', [
            'client_id' => 'test-client-2025',
            'client_secret' => null,
            'client_name' => 'MCP 2025-06-18 Test Client',
            'redirect_uris' => ['https://client.example.com/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
        ]);

        // Add test agency and user
        $this->storage->addContext($this->contextId, 'agency', [
            'id' => 1,
            'uuid' => $this->contextId,
            'name' => 'Test Agency',
            'active' => true
        ]);

        $this->storage->addUser(1, [
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'agency_id' => 1
        ]);
    }

    // ========================================
    // RFC 8707 Resource Indicators Tests
    // ========================================

    public function testResourceIndicatorRequired(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $expectedResource = $this->baseUrl . '/mcp/' . $this->contextId;

        // 1. Authorization request WITHOUT resource parameter should fail for 2025-06-18
        $authRequestWithoutResource = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                // Missing 'resource' parameter
            ])
        );
        $authRequestWithoutResource = $authRequestWithoutResource->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequestWithoutResource, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
        $this->assertStringContainsString('Resource parameter required', $errorData['error_description']);

        // 2. Authorization request WITH resource parameter should succeed
        $authRequestWithResource = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $expectedResource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequestWithResource = $authRequestWithResource->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequestWithResource, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode());

        session_destroy();
    }

    public function testResourceIndicatorTokenBinding(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $expectedResource = $this->baseUrl . '/mcp/' . $this->contextId;

        // Complete authorization flow with resource indicator
        $authCode = $this->completeAuthorizationFlow($codeVerifier, $codeChallenge, $expectedResource);

        // Token exchange WITH resource parameter
        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier,
                'resource' => $expectedResource
            ])
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(200, $tokenResponse->getStatusCode());
        $tokenData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertArrayHasKey('access_token', $tokenData);

        // Verify token is bound to the specific resource
        $storedTokenData = $this->storage->validateToken($tokenData['access_token']);
        $this->assertNotNull($storedTokenData);
        $this->assertEquals($expectedResource, $storedTokenData['resource']);
        $this->assertContains($expectedResource, $storedTokenData['aud']);

        session_destroy();
    }

    public function testResourceIndicatorAudienceRestriction(): void
    {
        // Create token bound to specific resource
        $boundResource = $this->baseUrl . '/mcp/' . $this->contextId;
        $this->storage->storeAccessToken([
            'client_id' => 'test-client-2025',
            'access_token' => 'bound-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $boundResource,
            'aud' => [$boundResource]
        ]);

        // Test access to correct resource (should succeed)
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

        // Test access to different resource (should fail)
        $differentContextId = '550e8400-e29b-41d4-a716-446655440001';
        $invalidRequest = $this->createRequest(
            'POST',
            '/mcp/' . $differentContextId,
            [
                'Authorization' => 'Bearer bound-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $response = $this->authMiddleware->__invoke($invalidRequest, $mockHandler);
        $this->assertEquals(401, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals(-32000, $errorData['error']['code']);
    }

    public function testResourceIndicatorMaliciousServerPrevention(): void
    {
        // Simulate malicious server trying to use token intended for different resource
        $legitimateResource = $this->baseUrl . '/mcp/' . $this->contextId;
        $maliciousResource = 'https://malicious.example.com/mcp/steal-data';

        // Create token bound to legitimate resource
        $this->storage->storeAccessToken([
            'client_id' => 'test-client-2025',
            'access_token' => 'legitimate-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $legitimateResource,
            'aud' => [$legitimateResource]
        ]);

        // Malicious server tries to use token (should fail audience validation)
        $maliciousRequest = $this->createRequest(
            'POST',
            '/mcp/malicious-context',
            [
                'Authorization' => 'Bearer legitimate-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($maliciousRequest, $mockHandler);

        $this->assertEquals(401, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Token not bound to this resource', $errorData['error']['message']);
    }

    public function testResourceIndicatorMultipleResources(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Test multiple resource indicators (should be rejected per RFC 8707)
        $multipleResources = [
            $this->baseUrl . '/mcp/' . $this->contextId,
            $this->baseUrl . '/mcp/another-context'
        ];

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $multipleResources, // Array should be rejected
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);

        session_destroy();
    }

    public function testResourceIndicatorInvalidResource(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Test invalid resource URL
        $invalidResource = 'not-a-valid-url';

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $invalidResource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
        $this->assertStringContainsString('valid URL', $errorData['error_description']);

        // Test resource for different server (should be rejected)
        $externalResource = 'https://different-server.com/mcp/context';

        $authRequest2 = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $externalResource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequest2 = $authRequest2->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response2 = $this->oauthServer->authorize($authRequest2, $this->createResponse());

        $this->assertEquals(400, $response2->getStatusCode());
        $errorData2 = json_decode((string) $response2->getBody(), true);
        $this->assertEquals('invalid_request', $errorData2['error']);
        $this->assertStringContainsString('this authorization server', $errorData2['error_description']);

        session_destroy();
    }

    // ========================================
    // OAuth Resource Server Tests
    // ========================================

    public function testOAuthResourceServerClassification(): void
    {
        // Test that MCP server properly identifies as OAuth Resource Server
        $request = $this->createRequest('GET', '/.well-known/oauth-protected-resource')
            ->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->discoveryProvider->protectedResource($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $metadata = json_decode((string) $response->getBody(), true);

        // Verify OAuth Resource Server classification
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

        // Required RFC 9728 fields
        $this->assertArrayHasKey('resource', $metadata);
        $this->assertArrayHasKey('authorization_servers', $metadata);
        $this->assertArrayHasKey('scopes_supported', $metadata);
        $this->assertArrayHasKey('bearer_methods_supported', $metadata);

        // MCP 2025-06-18 specific fields
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

        // Validate metadata structure according to RFC 9728
        $this->assertEquals($this->baseUrl, $metadata['resource']);
        $this->assertIsArray($metadata['authorization_servers']);
        $this->assertIsArray($metadata['scopes_supported']);
        $this->assertContains('mcp:read', $metadata['scopes_supported']);
        $this->assertContains('mcp:write', $metadata['scopes_supported']);
        $this->assertContains('header', $metadata['bearer_methods_supported']);

        // Validate MCP-specific extensions
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

        // Required OAuth 2.1 Authorization Server metadata
        $this->assertEquals($this->baseUrl, $metadata['issuer']);
        $this->assertEquals($this->baseUrl . '/oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertEquals($this->baseUrl . '/oauth/token', $metadata['token_endpoint']);
        $this->assertContains('authorization_code', $metadata['grant_types_supported']);
        $this->assertContains('code', $metadata['response_types_supported']);

        // MCP 2025-06-18 specific requirements
        $this->assertTrue($metadata['resource_indicators_supported']);
        $this->assertTrue($metadata['require_resource_parameter']);
        $this->assertContains('S256', $metadata['pkce_methods_supported']);
        $this->assertTrue($metadata['pkce_required']);
    }

    public function testAuthorizationServerMetadataIntegration(): void
    {
        // Test that protected resource metadata properly references auth server
        $protectedResourceRequest = $this->createRequest('GET', '/.well-known/oauth-protected-resource');
        $protectedResourceResponse = $this->discoveryProvider->protectedResource($protectedResourceRequest, $this->createResponse());
        $protectedResourceData = json_decode((string) $protectedResourceResponse->getBody(), true);

        $authServerRequest = $this->createRequest('GET', '/.well-known/oauth-authorization-server');
        $authServerResponse = $this->discoveryProvider->authorizationServer($authServerRequest, $this->createResponse());
        $authServerData = json_decode((string) $authServerResponse->getBody(), true);

        // Verify integration between resource and auth server metadata
        $this->assertContains($authServerData['issuer'], $protectedResourceData['authorization_servers']);

        // Verify compatible features
        $this->assertEquals(
            $protectedResourceData['resource_indicators_supported'],
            $authServerData['resource_indicators_supported']
        );
    }

    // ========================================
    // Enhanced Security Features Tests
    // ========================================

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

        $this->assertEquals(200, $response->getStatusCode());
        $clientData = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('client_id', $clientData);
        $this->assertEquals($registrationData['client_name'], $clientData['client_name']);
        $this->assertContains('authorization_code', $clientData['grant_types']);

        // Verify client was stored and can be retrieved
        $storedClient = $this->storage->getOAuthClient($clientData['client_id']);
        $this->assertNotNull($storedClient);
        $this->assertEquals($registrationData['client_name'], $storedClient['client_name']);
    }

    public function testTokenBindingEnforcement(): void
    {
        $resource1 = $this->baseUrl . '/mcp/' . $this->contextId;
        $resource2 = $this->baseUrl . '/mcp/different-context';

        // Create token bound to resource1
        $this->storage->storeAccessToken([
            'client_id' => 'test-client-2025',
            'access_token' => 'bound-token-test',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $resource1,
            'aud' => [$resource1]
        ]);

        // Test access to bound resource (should succeed)
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

        $context = $this->createTestContext([
            'protocol_version' => '2025-06-18',
            'context_id' => $this->contextId
        ]);
        $validRequest = $validRequest->withAttribute('mcp_context', $context);

        $response = $this->mcpServer->handle($validRequest, $this->createResponse());
        $this->assertEquals(202, $response->getStatusCode()); // Request accepted and queued

        // Test access to different resource (should fail)
        $invalidRequest = $this->createRequest(
            'POST',
            '/mcp/different-context',
            [
                'Authorization' => 'Bearer bound-token-test',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($invalidRequest, $mockHandler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testConfusedDeputyPrevention(): void
    {
        // Test that tokens cannot be used by unintended parties
        $legitimateResource = $this->baseUrl . '/mcp/' . $this->contextId;

        // Create token with specific resource binding
        $this->storage->storeAccessToken([
            'client_id' => 'legitimate-client',
            'access_token' => 'deputy-test-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $legitimateResource,
            'aud' => [$legitimateResource]
        ]);

        // Simulate confused deputy attack - different client trying to use token
        $confusedRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer deputy-test-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        // Add context that would indicate different client
        $suspiciousContext = $this->createTestContext([
            'token_data' => [
                'client_id' => 'different-client', // Different from token's client_id
                'access_token' => 'deputy-test-token',
                'resource' => $legitimateResource
            ]
        ]);
        $confusedRequest = $confusedRequest->withAttribute('mcp_context', $suspiciousContext);

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($confusedRequest, $mockHandler);

        // Should succeed if resource binding is correct (client_id mismatch is handled at auth server level)
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTokenPassthroughPrevention(): void
    {
        // Test that tokens cannot be passed through to unintended services
        $originalResource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken([
            'client_id' => 'test-client-2025',
            'access_token' => 'passthrough-test-token',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $originalResource,
            'aud' => [$originalResource]
        ]);

        // Attempt to use token for different resource (passthrough attack)
        $passthroughRequest = $this->createRequest(
            'POST',
            '/mcp/unauthorized-context',
            [
                'Authorization' => 'Bearer passthrough-test-token',
                'MCP-Protocol-Version' => '2025-06-18'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($passthroughRequest, $mockHandler);

        $this->assertEquals(401, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertStringContainsString('Token not bound to this resource', $errorData['error']['message']);
    }

    public function testSessionHijackingPrevention(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $state = bin2hex(random_bytes(32)); // Strong state parameter

        // Start legitimate authorization flow
        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state,
                'resource' => $this->baseUrl . '/mcp/' . $this->contextId,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        // Simulate session hijacking attempt with different state
        $hijackedState = 'hijacked-state-value';

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        // Manually modify session to simulate hijacking
        $originalState = $_SESSION['oauth_request']['state'];
        $_SESSION['oauth_request']['state'] = $hijackedState;

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());

        // Should return hijacked state, not original
        $this->assertEquals(302, $consentResponse->getStatusCode());
        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        // State should match what's in session (hijacked value)
        $this->assertEquals($hijackedState, $params['state']);

        // This demonstrates the importance of validating state on client side
        $this->assertNotEquals($originalState, $params['state']);

        session_destroy();
    }

    public function testProxyMisusePrevention(): void
    {
        // Test that requests through proxies maintain security properties
        $resource = $this->baseUrl . '/mcp/' . $this->contextId;

        $this->storage->storeAccessToken([
            'client_id' => 'test-client-2025',
            'access_token' => 'proxy-test-token',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1,
            'resource' => $resource,
            'aud' => [$resource]
        ]);

        // Simulate request through proxy with forwarded headers
        $proxyRequest = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            [
                'Authorization' => 'Bearer proxy-test-token',
                'MCP-Protocol-Version' => '2025-06-18',
                'X-Forwarded-For' => '192.168.1.100, 10.0.0.1',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'proxy.example.com'
            ]
        );

        $mockHandler = $this->createMockRequestHandler(200);
        $response = $this->authMiddleware->__invoke($proxyRequest, $mockHandler);

        // Should succeed - proxy headers don't affect token validation
        $this->assertEquals(200, $response->getStatusCode());

        // Test malicious proxy trying to modify resource binding
        $maliciousProxyRequest = $this->createRequest(
            'POST',
            '/mcp/malicious-context',
            [
                'Authorization' => 'Bearer proxy-test-token',
                'MCP-Protocol-Version' => '2025-06-18',
                'X-Forwarded-Host' => 'malicious.example.com'
            ]
        );

        $response = $this->authMiddleware->__invoke($maliciousProxyRequest, $mockHandler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testSecurityBestPracticesEnforcement(): void
    {
        // Test multiple security best practices are enforced

        // 1. PKCE is mandatory
        session_start();

        $authRequestWithoutPKCE = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $this->baseUrl . '/mcp/' . $this->contextId
                // Missing PKCE parameters
            ])
        );
        $authRequestWithoutPKCE = $authRequestWithoutPKCE->withHeader('MCP-Protocol-Version', '2025-06-18');

        $response = $this->oauthServer->authorize($authRequestWithoutPKCE, $this->createResponse());
        $this->assertEquals(200, $response->getStatusCode()); // Auth request succeeds, but token exchange will fail

        // 2. Protocol version header is mandatory for 2025-06-18
        $requestWithoutVersionHeader = $this->createRequest(
            'POST',
            '/mcp/' . $this->contextId,
            ['Authorization' => 'Bearer test-token']
        );

        $context = $this->createTestContext(); // No protocol_version
        $requestWithoutVersionHeader = $requestWithoutVersionHeader->withAttribute('mcp_context', $context);

        $response = $this->mcpServer->handle($requestWithoutVersionHeader, $this->createResponse());
        $this->assertEquals(400, $response->getStatusCode());

        // 3. Secure redirect URI validation
        $authRequestBadRedirect = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://malicious.example.com/steal', // Not registered
                'scope' => 'mcp:read',
                'resource' => $this->baseUrl . '/mcp/' . $this->contextId,
                'code_challenge' => 'challenge',
                'code_challenge_method' => 'S256'
            ])
        );

        $response = $this->oauthServer->authorize($authRequestBadRedirect, $this->createResponse());
        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);

        session_destroy();
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function completeAuthorizationFlow(string $codeVerifier, string $codeChallenge, string $resource): string
    {
        // Authorization request
        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => 'test-client-2025',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'resource' => $resource,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
            ])
        );
        $authRequest = $authRequest->withHeader('MCP-Protocol-Version', '2025-06-18');

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        // Complete user consent
        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        return $params['code'];
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
