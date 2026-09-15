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

        $this->storage->addOAuthClient(
            'test-client-id',
            [
            'client_id' => 'test-client-id',
            'client_secret' => null,
            'client_name' => 'Test MCP Client',
            'redirect_uris' => ['https://client.example.com/callback', 'urn:ietf:wg:oauth:2.0:oob'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']
            ]
        );

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
     * OAuth 2.1 REQUIREMENT: PKCE is mandatory for all clients
     */
    public function testAuthorizationCodeFlowWithPKCE(): void
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
                'scope' => 'mcp:read mcp:write',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256'
                ]
            )
        );

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());

        $this->assertEquals(200, $authResponse->getStatusCode());
        $this->assertStringContainsString('Test MCP Client', (string) $authResponse->getBody());

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
        $authCode = $params['code'];
        $this->assertNotEmpty($authCode);
        $this->assertEquals($state, $params['state']);

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
        $authRequest = $this->createRequest(
            'GET',
            '/oauth/authorize?' . http_build_query(
                [
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://client.example.com/callback',
                'scope' => 'mcp:read mcp:write',
                'state' => bin2hex(random_bytes(16))
                ]
            )
        );

        $authResponse = $this->oauthServer->authorize($authRequest, $this->createResponse());

        $this->assertEquals(400, $authResponse->getStatusCode());

        $error = json_decode((string) $authResponse->getBody(), true);
        $this->assertEquals('invalid_request', $error['error']);
        $this->assertStringContainsString('code_challenge', $error['error_description']);
    }

    public function testRefreshTokenRotation(): void
    {

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

        $newAccessToken = $tokenData['access_token'];
        $newRefreshToken = $tokenData['refresh_token'];

        $this->assertNotEquals($originalAccessToken, $newAccessToken);
        $this->assertNotEquals($originalRefreshToken, $newRefreshToken);
        $this->assertEquals('Bearer', $tokenData['token_type']);
        $this->assertEquals(3600, $tokenData['expires_in']);

        $originalTokenData = $this->storage->validateToken($originalAccessToken);
        $this->assertNull($originalTokenData, 'Original access token should be revoked');

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

        $thirdRefreshRequest = $this->createRequest('POST', '/oauth/token')
            ->withParsedBody(
                [
                'grant_type' => 'refresh_token',
                'refresh_token' => $newRefreshToken,
                'client_id' => 'test-client-id'
                ]
            );

        $thirdRefreshResponse = $this->oauthServer->token($thirdRefreshRequest, $this->createResponse());

        $this->assertEquals(
            400,
            $thirdRefreshResponse->getStatusCode(),
            'Replaying a rotated refresh token revokes the whole family, including the current token'
        );
    }

    public function testRefreshTokenWithExpiredToken(): void
    {

        $this->storage->storeAccessToken(
            [
            'access_token' => 'expired-access-token',
            'refresh_token' => 'valid-refresh-token',
            'client_id' => 'test-client-id',
            'scope' => 'mcp:read',
            'expires_at' => time() - 3600,
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
            ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']);

        $consentResponse = $this->oauthServer->consent($consentRequest, $this->createResponse());
        $location = $consentResponse->getHeaderLine('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $authCode = $params['code'];

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
                'client_id' => 'confidential-client',
                'client_secret' => 'wrong-secret',
                'redirect_uri' => 'https://confidential.example.com/callback',
                'code_verifier' => $codeVerifier
                ]
            );

        $tokenResponse = $this->oauthServer->token($tokenRequest, $this->createResponse());

        $this->assertEquals(401, $tokenResponse->getStatusCode());
        $errorData = json_decode((string) $tokenResponse->getBody(), true);
        $this->assertEquals('invalid_client', $errorData['error']);

        session_destroy();
    }

    public function testTokenRevocation(): void
    {

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

        $this->assertNotNull($this->storage->validateToken('revoke-test-token'));

        $revokeRequest = $this->createRequest('POST', '/oauth/revoke')
            ->withParsedBody(
                [
                'token' => 'revoke-test-token',
                'client_id' => 'test-client-id'
                ]
            );

        $revokeResponse = $this->oauthServer->revoke($revokeRequest, $this->createResponse());

        $this->assertEquals(200, $revokeResponse->getStatusCode());

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
                ->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $this->assertEquals(200, $consentResponse->getStatusCode());
        $this->assertEquals('text/html', $consentResponse->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Authorization Successful', (string) $consentResponse->getBody());

        session_destroy();
    }

    /**
     * RFC 8707: the resource parameter is optional and only validated when present
     */
    public function testAuthorizeAcceptsRequestWithoutResourceParameter(): void
    {
        $response = $this->authorizeWithResource(null);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Test MCP Client', (string) $response->getBody());
    }

    /**
     * @dataProvider invalidResourceProvider
     */
    public function testAuthorizeRejectsMalformedResourceParameter(string $resource): void
    {
        $response = $this->authorizeWithResource($resource);
        $error = json_decode((string) $response->getBody(), true);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('invalid_request', $error['error']);
    }

    public static function invalidResourceProvider(): array
    {
        return [
            'fragment' => ['https://localhost:8080/mcp/agency#fragment'],
            'traversal' => ['https://localhost:8080/mcp/../admin'],
            'foreign host' => ['https://other.example.com/mcp/agency']
        ];
    }

    private function authorizeWithResource(?string $resource): \Psr\Http\Message\ResponseInterface
    {
        $params = [
            'response_type' => 'code',
            'client_id' => 'test-client-id',
            'redirect_uri' => 'https://client.example.com/callback',
            'scope' => 'mcp:read mcp:write',
            'state' => 'state-value',
            'code_challenge' => $this->generateCodeChallenge($this->generateCodeVerifier()),
            'code_challenge_method' => 'S256'
        ];

        if ($resource !== null) {
            $params['resource'] = $resource;
        }

        $response = $this->oauthServer->authorize(
            $this->createRequest('GET', '/oauth/authorize')->withQueryParams($params),
            $this->createResponse()
        );

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];

        return $response;
    }

    /**
     * OAuth pages follow the operating system color scheme
     */
    public function testOAuthPagesSupportLightAndDark(): void
    {
        $html = (string) $this->renderConsent($this->oauthServer)->getBody();

        $this->assertStringContainsString("<meta name='color-scheme' content='light dark'>", $html);
        $this->assertStringContainsString('color-scheme: light dark', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);

        [$light, $dark] = $this->extractSchemes($html);

        $this->assertNotEquals($light['--bg'], $dark['--bg']);
        $this->assertNotEquals($light['--fg'], $dark['--fg']);
        $this->assertNotEquals($light['--surface'], $dark['--surface']);
    }

    public function testAuthorizationCodePageIsThemed(): void
    {
        session_start();

        $codeVerifier = $this->generateCodeVerifier();

        $this->oauthServer->authorize(
            $this->createRequest('GET', '/oauth/authorize')
                ->withQueryParams(
                    [
                    'response_type' => 'code',
                    'client_id' => 'test-client-id',
                    'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
                    'scope' => 'mcp:read mcp:write',
                    'state' => 'state-value',
                    'code_challenge' => $this->generateCodeChallenge($codeVerifier),
                    'code_challenge_method' => 'S256'
                    ]
                ),
            $this->createResponse()
        );

        $_SESSION['oauth_user'] = [
            'user_id' => 1,
            'agency_id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ];

        $response = $this->oauthServer->consent(
            $this->createRequest('POST', '/oauth/consent')->withParsedBody(['action' => 'allow', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $html = (string) $response->getBody();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Authorization Successful', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);

        [$light, $dark] = $this->extractSchemes($html);
        $this->assertNotEquals($light['--bg'], $dark['--bg']);

        session_destroy();
    }

    public function testOAuthPageColorsAreConfigurable(): void
    {
        $server = $this->createThemedServer(
            [
                'background_color' => '#101820',
                'text_color' => '#f2f4f8',
                'accent_color' => ['light' => '#ff6b35', 'dark' => '#ffa07a']
            ]
        );

        [$light, $dark] = $this->extractSchemes((string) $this->renderConsent($server)->getBody());

        $this->assertEquals('#101820', $light['--bg']);
        $this->assertEquals('#101820', $dark['--bg']);
        $this->assertEquals('#f2f4f8', $light['--fg']);
        $this->assertEquals('#f2f4f8', $dark['--fg']);
        $this->assertEquals('#ff6b35', $light['--accent']);
        $this->assertEquals('#ffa07a', $dark['--accent']);
    }

    public function testUnsetOAuthPageColorsKeepTheirDefaults(): void
    {
        $server = $this->createThemedServer(['accent_color' => '#ff6b35']);

        [$light, $dark] = $this->extractSchemes((string) $this->renderConsent($server)->getBody());

        $this->assertEquals('#ff6b35', $light['--accent']);
        $this->assertNotEquals($light['--bg'], $dark['--bg']);
        $this->assertNotEquals($light['--fg'], $dark['--fg']);
    }

    /**
     * @dataProvider invalidColorProvider
     */
    public function testOAuthPageColorsRejectStyleInjection(string $color): void
    {
        $server = $this->createThemedServer(['background_color' => $color]);
        $html = (string) $this->renderConsent($server)->getBody();

        [$light, $dark] = $this->extractSchemes($html);

        $this->assertStringNotContainsString('evil.example.com', $html);
        $this->assertEquals('#f6f8fa', $light['--bg']);
        $this->assertEquals('#0d1117', $dark['--bg']);
    }

    public static function invalidColorProvider(): array
    {
        return [
            'declaration break out' => ['red; } body { background: url(https://evil.example.com/x.png)'],
            'url value' => ['url(https://evil.example.com/x.png)'],
            'expression' => ['image-set("https://evil.example.com/x.png")'],
            'comment escape' => ['#fff; } /* evil.example.com */ :root { --fg: red'],
            'empty' => ['']
        ];
    }

    private function createThemedServer(array $ui): OAuthServer
    {
        return new OAuthServer(
            $this->storage,
            $this->responseFactory,
            $this->streamFactory,
            [
                'base_url' => 'https://localhost:8080',
                'oauth' => ['ui' => $ui]
            ]
        );
    }

    /**
     * Pull the light and dark custom property values out of a rendered page
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function extractSchemes(string $html): array
    {
        $this->assertSame(
            1,
            preg_match('/:root \{ color-scheme: light dark; (.+?) \}/', $html, $lightMatch),
            'No light palette found'
        );
        $this->assertSame(
            1,
            preg_match('/@media \(prefers-color-scheme: dark\) \{ :root \{ (.+?) \} \}/', $html, $darkMatch),
            'No dark palette found'
        );

        return [$this->parseDeclarations($lightMatch[1]), $this->parseDeclarations($darkMatch[1])];
    }

    /**
     * @return array<string, string>
     */
    private function parseDeclarations(string $declarations): array
    {
        $parsed = [];

        foreach (explode(';', $declarations) as $declaration) {
            if (strpos($declaration, ':') === false) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $parsed[trim($property)] = trim($value);
        }

        $this->assertNotEmpty($parsed);

        return $parsed;
    }

    private function renderConsent(OAuthServer $server): \Psr\Http\Message\ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['oauth_csrf'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_request'] = [
            'client_id' => 'test-client-id',
            'client_name' => 'Test MCP Client',
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
            $this->createRequest('POST', '/oauth/consent')->withParsedBody(['action' => 'invalid', 'csrf_token' => $_SESSION['oauth_csrf'] ?? '']),
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        session_destroy();
        $_SESSION = [];

        return $response;
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
