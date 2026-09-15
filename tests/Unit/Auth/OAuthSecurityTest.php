<?php

namespace Seolinkmap\Waasup\Tests\Unit\Auth;

use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * OAuth 2.1 Security Compliance Tests
 */
class OAuthSecurityTest extends TestCase
{
    private OAuthServer $oauthServer;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new MemoryStorage();
        $this->setupTestData();

        $config = [
            'base_url' => 'https://localhost:8080',
            'session_lifetime' => 3600
        ];

        $this->oauthServer = new OAuthServer(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            $config
        );
    }

    private function setupTestData(): void
    {

        $this->storage->addOAuthClient(
            'test-client-id',
            [
            'client_id' => 'test-client-id',
            'client_secret' => null,
            'client_name' => 'Test MCP Client',
            'redirect_uris' => [
                'https://client.example.com/callback',
                'https://app.client.com/oauth/callback',
                'https://localhost:3000/callback'
            ],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
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

        $this->storage->addContext(
            'agency-uuid',
            'agency',
            [
            'id' => 1,
            'uuid' => 'agency-uuid',
            'name' => 'Test Agency',
            'active' => true
            ]
        );
    }

    /**
     * OAuth 2.1 REQUIREMENT: State parameter validation for CSRF protection
     */
    public function testStateParameterValidation(): void
    {
        session_start();

        $state = bin2hex(random_bytes(16));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());
        $this->assertEquals(200, $authResponse->getStatusCode());

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());

        $this->assertEquals(302, $consentResponse->getStatusCode());
        $location = $consentResponse->getHeaderLine('Location');

        $this->assertStringContainsString('https://client.example.com/callback', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $this->assertEquals($state, $params['state'], 'State parameter must be returned unchanged');
        $this->assertNotEmpty($params['code'], 'Authorization code must be present');

        session_destroy();
    }

    public function testStateParameterPreservationAcrossFlow(): void
    {
        session_start();

        $originalState = 'test-state-12345';
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read mcp:write',
                'state' => $originalState,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $this->assertEquals($originalState, $_SESSION['oauth_request']['state']);

        $consentResponse = $this->oauthServer->consent(
            $this->createRequest('POST', '/oauth/consent')
                ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertEquals($originalState, $params['state'], 'Original state must be preserved');

        session_destroy();
    }

    /**
     * OAuth 2.1 REQUIREMENT: Strict redirect URI matching
     */
    public function testRedirectUriStrictMatching(): void
    {
        session_start();

        $state = bin2hex(random_bytes(16));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $validAuthRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $validResponse = $this->oauthServer->authorize($validAuthRequest, $this->createResponse());
        $this->assertEquals(200, $validResponse->getStatusCode());

        session_destroy();
        session_start();

        $invalidAuthRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback/evil',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $invalidResponse = $this->oauthServer->authorize($invalidAuthRequest, $this->createResponse());
        $this->assertEquals(400, $invalidResponse->getStatusCode());

        $errorData = json_decode((string) $invalidResponse->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
        $this->assertStringContainsString('redirect_uri', $errorData['error_description']);

        session_destroy();
    }

    public function testRedirectUriSubdomainAttack(): void
    {
        $state = bin2hex(random_bytes(16));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $maliciousRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://evil.client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $response = $this->oauthServer->authorize($maliciousRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
    }

    public function testRedirectUriParameterInjection(): void
    {
        $state = bin2hex(random_bytes(16));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $maliciousRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback?evil=param',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $response = $this->oauthServer->authorize($maliciousRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
    }

    public function testRedirectUriFragmentAttack(): void
    {
        $state = bin2hex(random_bytes(16));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $maliciousRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback#evil',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $response = $this->oauthServer->authorize($maliciousRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('invalid_request', $errorData['error']);
    }

    /**
     * Authorization code replay prevention
     */
    public function testAuthorizationCodeReplayPrevention(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $state = bin2hex(random_bytes(16));

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentResponse = $this->oauthServer->consent(
            $this->createRequest('POST', '/oauth/consent')
                ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        $firstTokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $firstTokenResponse = $this->oauthServer->token($firstTokenRequest, $this->createResponse());
        $this->assertEquals(200, $firstTokenResponse->getStatusCode());

        $firstTokenData = json_decode((string) $firstTokenResponse->getBody(), true);
        $this->assertArrayHasKey('access_token', $firstTokenData);

        $replayTokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $replayTokenResponse = $this->oauthServer->token($replayTokenRequest, $this->createResponse());
        $this->assertEquals(400, $replayTokenResponse->getStatusCode());

        $replayErrorData = json_decode((string) $replayTokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $replayErrorData['error']);

        session_destroy();
    }

    public function testAuthorizationCodeExpiration(): void
    {

        $expiredCode = 'expired-auth-code-123';
        $this->storage->storeAuthorizationCode(
            $expiredCode,
            [
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read',
            'expires_at' => time() - 300,
            'code_challenge' => null,
            'code_challenge_method' => null,
            'agency_id' => 1,
            'user_id' => 1
            ]
        );

        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $expiredCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback'
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);
    }

    public function testClientMismatchInTokenExchange(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentResponse = $this->oauthServer->consent(
            $this->createRequest('POST', '/oauth/consent')
                ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'different-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);

        session_destroy();
    }

    public function testRedirectUriMismatchInTokenExchange(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $this->oauthServer->authorize($authRequest, $this->createResponse());

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentResponse = $this->oauthServer->consent(
            $this->createRequest('POST', '/oauth/consent')
                ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://app.client.com/oauth/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);

        session_destroy();
    }

    public function testMissingRequiredParameters(): void
    {

        $missingResponseType = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read'
                ]
            )
        );

        $response1 = $this->oauthServer->authorize($missingResponseType, $this->createResponse());
        $this->assertEquals(400, $response1->getStatusCode());

        $missingClientId = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read'
                ]
            )
        );

        $response2 = $this->oauthServer->authorize($missingClientId, $this->createResponse());
        $this->assertEquals(400, $response2->getStatusCode());

        $missingRedirectUri = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'scope' => 'mcp:read'
                ]
            )
        );

        $response3 = $this->oauthServer->authorize($missingRedirectUri, $this->createResponse());
        $this->assertEquals(400, $response3->getStatusCode());
    }

    public function testUnsupportedResponseType(): void
    {
        $request = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'token',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read'
                ]
            )
        );

        $response = $this->oauthServer->authorize($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('unsupported_response_type', $errorData['error']);
    }

    public function testInvalidClient(): void
    {
        $request = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'nonexistent-client',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read'
                ]
            )
        );

        $response = $this->oauthServer->authorize($request, $this->createResponse());

        $this->assertEquals(401, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('unauthorized_client', $errorData['error']);
    }

    /**
     * OAuth 2.1 REQUIREMENT: Bearer tokens must not be included in query strings
     */
    public function testBearerTokenNotInQueryString(): void
    {

        $this->storage->storeAccessToken(
            [
            'access_token' => 'test-bearer-token',
            'refresh_token' => 'test-refresh-token',
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1
            ]
        );

        $requestWithTokenInQuery = $this->createRequest(
            'GET',
            '/mcp/550e8400-e29b-41d4-a716-446655440000?access_token=test-bearer-token'
        );

        $authMiddleware = new \Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            ['context_types' => ['agency']]
        );

        $mockHandler = new class () implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(200);
            }
        };

        $response = $authMiddleware($requestWithTokenInQuery, $mockHandler);

        $this->assertEquals(401, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals(-32000, $errorData['error']['code']);
        $this->assertEquals('Authentication required', $errorData['error']['message']);
    }

    /**
     * OAuth 2.1 REQUIREMENT: Implicit grant (response_type=token) must be rejected
     */
    public function testImplicitGrantRejection(): void
    {
        $state = bin2hex(random_bytes(16));

        $implicitRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'token',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state
                ]
            )
        );

        $response = $this->oauthServer->authorize($implicitRequest, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
        $errorData = json_decode((string) $response->getBody(), true);
        $this->assertEquals('unsupported_response_type', $errorData['error']);
        $this->assertStringContainsString('authorization code flow', $errorData['error_description']);
    }

    /**
     * Helper methods for PKCE
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
