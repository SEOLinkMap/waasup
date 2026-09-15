<?php

namespace Seolinkmap\Waasup\Tests\Unit\Auth;

use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

/**
 * Configurable OAuth lifetimes and sliding access token expiration
 */
class TokenLifetimeTest extends TestCase
{
    private MemoryStorage $storage;
    private string $contextId = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new MemoryStorage();

        $this->storage->addOAuthClient(
            'test-client-id',
            [
                'client_id' => 'test-client-id',
                'client_secret' => null,
                'client_name' => 'Test MCP Client',
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
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];

        parent::tearDown();
    }

    public function testAccessTokenLifetimeDefaultsToOneHour(): void
    {
        $tokenData = $this->exchangeAuthorizationCode($this->createOAuthServer([]));

        $this->assertEquals(3600, $tokenData['expires_in']);
        $this->assertEqualsWithDelta(
            time() + 3600,
            $this->storedToken($tokenData['access_token'])['expires_at'],
            5
        );
    }

    public function testAccessTokenLifetimeIsConfigurable(): void
    {
        $server = $this->createOAuthServer(['access_token_lifetime' => 7200]);
        $tokenData = $this->exchangeAuthorizationCode($server);

        $this->assertEquals(7200, $tokenData['expires_in']);
        $this->assertEqualsWithDelta(
            time() + 7200,
            $this->storedToken($tokenData['access_token'])['expires_at'],
            5
        );
    }

    public function testRefreshGrantUsesConfiguredLifetime(): void
    {
        $server = $this->createOAuthServer(['access_token_lifetime' => 1800]);
        $tokenData = $this->exchangeAuthorizationCode($server);

        $refreshed = $this->refresh($server, $tokenData['refresh_token']);

        $this->assertArrayNotHasKey('error', $refreshed);
        $this->assertEquals(1800, $refreshed['expires_in']);
        $this->assertEqualsWithDelta(
            time() + 1800,
            $this->storedToken($refreshed['access_token'])['expires_at'],
            5
        );
    }

    public function testRefreshTokenIsRejectedAfterConfiguredLifetime(): void
    {
        $server = $this->createOAuthServer(['refresh_token_lifetime' => 86400]);
        $tokenData = $this->exchangeAuthorizationCode($server);

        $this->ageToken($tokenData['access_token'], 90000);

        $response = $server->token(
            $this->createRequest('POST', '/oauth/token')
                ->withParsedBody(
                    [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $tokenData['refresh_token'],
                        'client_id' => 'test-client-id'
                    ]
                ),
            $this->createResponse()
        );

        $error = json_decode((string) $response->getBody(), true);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_grant', $error['error']);
        $this->assertEquals('Expired refresh token', $error['error_description']);
    }

    public function testRefreshTokenDoesNotExpireByDefault(): void
    {
        $server = $this->createOAuthServer([]);
        $tokenData = $this->exchangeAuthorizationCode($server);

        $this->ageToken($tokenData['access_token'], 31536000);

        $refreshed = $this->refresh($server, $tokenData['refresh_token']);

        $this->assertArrayNotHasKey('error', $refreshed);
        $this->assertNotEmpty($refreshed['access_token']);
    }

    public function testAuthorizationCodeLifetimeIsConfigurable(): void
    {
        $server = $this->createOAuthServer(['authorization_code_lifetime' => 900]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['oauth_csrf'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_request'] = [
            'client_id' => 'test-client-id',
            'redirect_uri' => 'https://client.example.com/callback',
            'scope' => 'mcp:read mcp:write',
            'state' => 'state-value',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256'
        ];
        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $response = $server->consent(
            $this->createRequest('POST', '/oauth/consent')->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $this->assertEquals(302, $response->getStatusCode());

        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $params);
        $this->assertNotEmpty($params['code'] ?? null);

        $authCode = $this->storage->getAuthorizationCode($params['code'], 'test-client-id');

        $this->assertNotNull($authCode);
        $this->assertEqualsWithDelta(time() + 900, $authCode['expires_at'], 5);
    }

    public function testSlidingExpirationIsDisabledByDefault(): void
    {
        $expiresAt = time() + 600;
        $this->addValidToken('static-token', $expiresAt);

        $this->handleAuthenticatedRequest('static-token', []);

        $this->assertEquals($expiresAt, $this->storedToken('static-token')['expires_at']);
    }

    public function testSlidingExpirationExtendsActiveToken(): void
    {
        $this->addValidToken('active-token', time() + 600);

        $this->handleAuthenticatedRequest(
            'active-token',
            [
                'access_token_lifetime' => 3600,
                'sliding_expiration' => true
            ]
        );

        $this->assertEqualsWithDelta(
            time() + 3600,
            $this->storedToken('active-token')['expires_at'],
            5
        );
    }

    public function testSlidingExpirationRespectsMaxLifetime(): void
    {
        $issuedAt = time() - 7000;
        $this->addValidToken('capped-token', time() + 100, $issuedAt);

        $this->handleAuthenticatedRequest(
            'capped-token',
            [
                'access_token_lifetime' => 3600,
                'sliding_expiration' => true,
                'sliding_expiration_max_lifetime' => 7200
            ]
        );

        $this->assertEqualsWithDelta(
            $issuedAt + 7200,
            $this->storedToken('capped-token')['expires_at'],
            5
        );
    }

    public function testSlidingExpirationStopsAtMaxLifetime(): void
    {
        $expiresAt = time() + 300;
        $this->addValidToken('exhausted-token', $expiresAt, time() - 7200);

        $this->handleAuthenticatedRequest(
            'exhausted-token',
            [
                'access_token_lifetime' => 3600,
                'sliding_expiration' => true,
                'sliding_expiration_max_lifetime' => 7200
            ]
        );

        $this->assertEquals($expiresAt, $this->storedToken('exhausted-token')['expires_at']);
    }

    public function testSlidingExpirationSkipsTokenExtendedWithinInterval(): void
    {
        $expiresAt = time() + 3570;
        $this->addValidToken('fresh-token', $expiresAt);

        $this->handleAuthenticatedRequest(
            'fresh-token',
            [
                'access_token_lifetime' => 3600,
                'sliding_expiration' => true,
                'sliding_expiration_interval' => 60
            ]
        );

        $this->assertEquals($expiresAt, $this->storedToken('fresh-token')['expires_at']);
    }

    public function testSlidingExpirationIntervalCanBeDisabled(): void
    {
        $expiresAt = time() + 3570;
        $this->addValidToken('every-request-token', $expiresAt);

        $this->handleAuthenticatedRequest(
            'every-request-token',
            [
                'access_token_lifetime' => 3600,
                'sliding_expiration' => true,
                'sliding_expiration_interval' => 0
            ]
        );

        $this->assertGreaterThan($expiresAt, $this->storedToken('every-request-token')['expires_at']);
    }

    private function createOAuthServer(array $oauthConfig): OAuthServer
    {
        return new OAuthServer(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            [
                'base_url' => 'https://localhost:8080',
                'oauth' => $oauthConfig
            ]
        );
    }

    private function exchangeAuthorizationCode(OAuthServer $server): array
    {
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $code = bin2hex(random_bytes(16));

        $this->storage->storeAuthorizationCode(
            $code,
            [
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read mcp:write',
                'expires_at' => time() + 300,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'agency_id' => 1,
                'user_id' => 1
            ]
        );

        $response = $server->token(
            $this->createRequest('POST', '/oauth/token')
                ->withParsedBody(
                    [
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'client_id' => 'test-client-id',
                        'redirect_uri' => 'https://client.example.com/callback',
                        'code_verifier' => $codeVerifier
                    ]
                ),
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    private function refresh(OAuthServer $server, string $refreshToken): array
    {
        $response = $server->token(
            $this->createRequest('POST', '/oauth/token')
                ->withParsedBody(
                    [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'client_id' => 'test-client-id'
                    ]
                ),
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Backdate an issued token without disturbing the rest of the stored row
     */
    private function ageToken(string $accessToken, int $seconds): void
    {
        $stored = $this->storedToken($accessToken);
        $stored['created_at'] = time() - $seconds;

        $this->storage->addToken($accessToken, $stored);

        $this->assertEquals(
            $stored['refresh_token'],
            $this->storedToken($accessToken)['refresh_token']
        );
    }

    private function storedToken(string $accessToken): array
    {
        $stored = $this->storage->validateToken($accessToken);

        $this->assertIsArray($stored, "Token {$accessToken} is missing or no longer valid");

        return $stored;
    }

    private function addValidToken(string $token, int $expiresAt, ?int $createdAt = null): void
    {
        $this->storage->addToken(
            $token,
            [
                'access_token' => $token,
                'client_id' => 'test-client-id',
                'scope' => 'mcp:read mcp:write',
                'expires_at' => $expiresAt,
                'created_at' => $createdAt ?? time(),
                'agency_id' => 1,
                'user_id' => 1,
                'revoked' => false
            ]
        );
    }

    private function handleAuthenticatedRequest(string $token, array $oauthConfig): void
    {
        $middleware = new AuthMiddleware(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            [
                'base_url' => 'https://localhost:8080',
                'auth' => [
                    'context_types' => ['agency'],
                    'required_scopes' => ['mcp:read']
                ],
                'oauth' => $oauthConfig
            ]
        );

        $request = $this->createRequest('POST', '/mcp/' . $this->contextId)
            ->withHeader('Authorization', 'Bearer ' . $token);

        $handler = new class ($this->responseFactory) implements \Psr\Http\Server\RequestHandlerInterface {
            private \Psr\Http\Message\ResponseFactoryInterface $responseFactory;

            public function __construct(\Psr\Http\Message\ResponseFactoryInterface $responseFactory)
            {
                $this->responseFactory = $responseFactory;
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->responseFactory->createResponse(200);
            }
        };

        $response = $middleware($request, $handler);

        $this->assertEquals(200, $response->getStatusCode(), 'The request was not authenticated');
    }
}
