<?php

namespace Seolinkmap\Waasup\Tests\Unit\Auth;

use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

class OAuthServerTest extends TestCase
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
        // Add test client
        $this->storage->addOAuthClient(
            'test-client-id',
            [
            'client_id' => 'test-client-id',
            'client_secret' => null, // Public client
            'client_name' => 'Test MCP Client',
            'redirect_uris' => ['https://client.example.com/callback', 'urn:ietf:wg:oauth:2.0:oob'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
            ]
        );

        // Add confidential client
        $this->storage->addOAuthClient(
            'confidential-client',
            [
            'client_id' => 'confidential-client',
            'client_secret' => 'secret123',
            'client_name' => 'Confidential Client',
            'redirect_uris' => ['https://confidential.example.com/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
            ]
        );

        // Add test user
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

        // Add test agency
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
     * OAuth 2.1 REQUIREMENT: PKCE is mandatory for all clients
     */
    public function testAuthorizationCodeFlowWithPKCE(): void
    {
        // Start session for OAuth flow
        session_start();

        // 1. Authorization request with PKCE
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
                'scope' => 'mcp:read mcp:write',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());

        // Should redirect to auth form
        $this->assertEquals(200, $authResponse->getStatusCode());
        $this->assertStringContainsString('Test MCP Client', (string) $authResponse->getBody());

        // 2. Simulate user authentication and consent
        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());

        // Should redirect with authorization code
        $this->assertEquals(302, $consentResponse->getStatusCode());
        $location = $consentResponse->getHeaderLine('Location');
        $this->assertStringContainsString('https://client.example.com/callback', $location);

        // Extract authorization code from redirect
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];
        $this->assertNotEmpty($authCode);
        $this->assertEquals($state, $params['state']);

        // 3. Token exchange with PKCE
        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(200, $tokenResponse->getStatusCode());
        $tokenData = json_decode((string) $tokenResponse->getBody(), true);

        $this->assertArrayHasKey('access_token', $tokenData);
        $this->assertArrayHasKey('refresh_token', $tokenData);
        $this->assertEquals('Bearer', $tokenData['token_type']);
        $this->assertEquals(3600, $tokenData['expires_in']);
        $this->assertEquals('mcp:read mcp:write', $tokenData['scope']);

        session_destroy();
    }

    /**
     * OAuth 2.1 REQUIREMENT: Requests without PKCE must fail
     */
    public function testAuthorizationCodeFlowFailsWithoutPKCE(): void
    {
        session_start();

        // 1. Authorization request WITHOUT PKCE
        $state = bin2hex(random_bytes(16));

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read',
                'state' => $state
                // Note: No code_challenge or code_challenge_method
                ]
            )
        );

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());

        // Should still allow authorization (PKCE validation happens at token exchange)
        $this->assertEquals(200, $authResponse->getStatusCode());

        // 2. Complete auth flow to get code
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
        $authCode = $params['code'];

        // 3. Token exchange WITHOUT code_verifier should fail
        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback'
                // Note: No code_verifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);
        $this->assertStringContainsString('code_verifier', $errorData['error_description']);

        session_destroy();
    }

    /**
     * OAuth 2.1 REQUIREMENT: Refresh token rotation
     */
    public function testRefreshTokenRotation(): void
    {
        // Setup: Create an access token with refresh token
        $originalAccessToken = 'original-access-token';
        $originalRefreshToken = 'original-refresh-token';

        $this->storage->storeAccessToken(
            [
            'access_token' => $originalAccessToken,
            'refresh_token' => $originalRefreshToken,
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read mcp:write',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1
            ]
        );

        // 1. Use refresh token to get new tokens
        $refreshRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'refresh_token',
                'refresh_token' => $originalRefreshToken,
                'client_id' => 'test-client-id'
                ]
            );

        $refreshResponse = $this->oauthServer->token($refreshRequest, $this->createResponse());

        $this->assertEquals(200, $refreshResponse->getStatusCode());
        $tokenData = json_decode((string) $refreshResponse->getBody(), true);

        // Should get new tokens
        $newAccessToken = $tokenData['access_token'];
        $newRefreshToken = $tokenData['refresh_token'];

        $this->assertNotEquals($originalAccessToken, $newAccessToken);
        $this->assertNotEquals($originalRefreshToken, $newRefreshToken);
        $this->assertEquals('Bearer', $tokenData['token_type']);
        $this->assertEquals(3600, $tokenData['expires_in']);

        // 2. Original access token should be revoked
        $originalTokenData = $this->storage->validateToken($originalAccessToken);
        $this->assertNull($originalTokenData, 'Original access token should be revoked');

        // 3. Original refresh token should no longer work
        $secondRefreshRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'refresh_token',
                'refresh_token' => $originalRefreshToken,
                'client_id' => 'test-client-id'
                ]
            );

        $secondRefreshResponse = $this->oauthServer->token($secondRefreshRequest, $this->createResponse());

        $this->assertEquals(400, $secondRefreshResponse->getStatusCode());
        $errorData = json_decode((string) $secondRefreshResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);

        // 4. New refresh token should work
        $thirdRefreshRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'refresh_token',
                'refresh_token' => $newRefreshToken,
                'client_id' => 'test-client-id'
                ]
            );

        $thirdRefreshResponse = $this->oauthServer->token($thirdRefreshRequest, $this->createResponse());
        $this->assertEquals(200, $thirdRefreshResponse->getStatusCode());
    }

    public function testRefreshTokenWithExpiredToken(): void
    {
        // Create expired access token with valid refresh token
        $this->storage->storeAccessToken(
            [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'valid-refresh-token',
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read',
            'expires_at' => time() - 3600, // Expired 1 hour ago
            'agency_id' => 1,
            'user_id' => 1
            ]
        );

        $refreshRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'refresh_token',
                'refresh_token' => 'valid-refresh-token',
                'client_id' => 'test-client-id'
                ]
            );

        $response = $this->oauthServer->token($refreshRequest, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $tokenData = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('access_token', $tokenData);
        $this->assertArrayHasKey('refresh_token', $tokenData);
        $this->assertNotEquals('expired-access-token', $tokenData['access_token']);
        $this->assertNotEquals('valid-refresh-token', $tokenData['refresh_token']);
    }

    public function testPKCEWithInvalidCodeVerifier(): void
    {
        session_start();

        // 1. Start authorization with PKCE
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

        $consentRequest = $this->createRequest('POST', '/oauth/consent')
            ->withParsedBody(['action' => 'allow']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());
        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        // 2. Use wrong code verifier
        $wrongCodeVerifier = $this->generateCodeVerifier();

        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'code_verifier' => $wrongCodeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_grant', $errorData['error']);
        $this->assertStringContainsString('code_verifier', $errorData['error_description']);

        session_destroy();
    }

    public function testConfidentialClientFlow(): void
    {
        session_start();

        // 1. Authorization for confidential client
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'confidential-client',
                'redirect_uri' => 'https://confidential.example.com/callback',
                'scope' => 'mcp:read mcp:write',
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
                ->withParsedBody(['action' => 'allow']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        // 2. Token exchange with client secret
        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'confidential-client',
                'client_secret' => 'secret123',
                'redirect_uri' => 'https://confidential.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(200, $tokenResponse->getStatusCode());
        $tokenData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertArrayHasKey('access_token', $tokenData);

        session_destroy();
    }

    public function testConfidentialClientWithWrongSecret(): void
    {
        session_start();

        // Setup auth code for confidential client
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'confidential-client',
                'redirect_uri' => 'https://confidential.example.com/callback',
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
                ->withParsedBody(['action' => 'allow']),
            $this->createResponse()
        );

        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

        // Token exchange with wrong client secret
        $tokenRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => 'confidential-client',
                'client_secret' => 'wrong-secret',
                'redirect_uri' => 'https://confidential.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(400, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_client', $errorData['error']);

        session_destroy();
    }

    public function testTokenRevocation(): void
    {
        // Create access token
        $this->storage->storeAccessToken(
            [
            'access_token' => 'revoke-test-token',
            'refresh_token' => 'revoke-test-refresh',
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'agency_id' => 1,
            'user_id' => 1
            ]
        );

        // Verify token is valid
        $this->assertNotNull($this->storage->validateToken('revoke-test-token'));

        // Revoke token
        $revokeRequest = $this->createRequest('POST', '/oauth/revoke')
            ->withParsedBody(['token' => 'revoke-test-token']);

        $revokeResponse = $this->oauthServer->revoke($revokeRequest, $this->createResponse());

        $this->assertEquals(200, $revokeResponse->getStatusCode());

        // Token should no longer be valid
        $this->assertNull($this->storage->validateToken('revoke-test-token'));
    }

    public function testOutOfBandRedirectUri(): void
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
                'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
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
                ->withParsedBody(['action' => 'allow']),
            $this->createResponse()
        );

        // Should return HTML page with auth code
        $this->assertEquals(200, $consentResponse->getStatusCode());
        $this->assertEquals('text/html', $consentResponse->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Authorization Successful', (string) $consentResponse->getBody());

        session_destroy();
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
