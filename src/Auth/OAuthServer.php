<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Seolinkmap\Waasup\Auth\Providers\{GithubProvider, GoogleProvider, LinkedinProvider};
use Seolinkmap\Waasup\Storage\StorageInterface;

class OAuthServer
{
    private StorageInterface $storage;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private array $config;
    private ?GoogleProvider $googleProvider = null;
    private ?LinkedinProvider $linkedinProvider = null;
    private ?GithubProvider $githubProvider = null;

    public function __construct(
        StorageInterface $storage,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->config = array_merge($this->getDefaultConfig(), $config);

        $this->initializeSocialProviders();
    }

    /**
     * OAuth 2.1 Authorization Endpoint
     */
    public function authorize(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $clientId = $params['client_id'] ?? null;
        $redirectUri = $params['redirect_uri'] ?? null;
        $scope = $params['scope'] ?? 'mcp:read';
        $state = $params['state'] ?? null;
        $responseType = $params['response_type'] ?? null;
        $codeChallenge = $params['code_challenge'] ?? null;
        $codeChallengeMethod = $params['code_challenge_method'] ?? null;

        if (!$responseType) {
            return $this->errorResponse('invalid_request', 'Missing response_type parameter');
        }

        if (!$clientId) {
            return $this->errorResponse('invalid_request', 'Missing client_id parameter');
        }

        if (!$redirectUri) {
            return $this->errorResponse('invalid_request', 'Missing redirect_uri parameter');
        }

        if ($responseType !== 'code') {
            return $this->errorResponse('unsupported_response_type', 'Only authorization code flow is supported');
        }

        $clientData = $this->storage->getOAuthClient($clientId);
        if (!$clientData) {
            return $this->errorResponse('unauthorized_client', 'Invalid client_id');
        }

        $registeredUris = is_string($clientData['redirect_uris']) ?
            json_decode($clientData['redirect_uris'], true) :
            $clientData['redirect_uris'];

        if (!in_array($redirectUri, $registeredUris) && $redirectUri !== 'urn:ietf:wg:oauth:2.0:oob') {
            return $this->errorResponse('invalid_request', 'Invalid redirect_uri');
        }

        // Handle direct MCP callback pattern
        $parsed = parse_url($redirectUri);
        $path = $parsed['path'] ?? '';
        if (strpos($path, '/mcp') === 0 && strpos($path, '/mcp-private') === false) {
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $redirectUri);
        }

        // Store OAuth request in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['oauth_request'] = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'client_name' => $clientData['client_name']
        ];

        // Check if user already authenticated
        if (isset($_SESSION['userID']) && $_SESSION['userID']) {
            $userData = $this->storage->getUserData($_SESSION['userID']);
            if ($userData) {
                $_SESSION['oauth_user'] = [
                    'user_id' => $userData['id'],
                    'agency_id' => $userData['agency_id'],
                    'name' => $userData['name'],
                    'email' => $userData['email']
                ];
                return $this->renderConsentScreen();
            }
        }

        return $this->renderOAuthVerification();
    }

    /**
     * Handle OAuth verification form submission
     */
    public function verify(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'OAuth request session expired');
        }

        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return $this->errorResponse('invalid_request', 'Invalid request data');
        }

        $provider = $data['provider'] ?? 'email';

        if ($provider === 'email') {
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($email) || empty($password)) {
                return $this->renderOAuthVerification(['error' => 'Email and password required']);
            }

            $result = $this->verifyEmailPassword($email, $password);
            if (!$result) {
                return $this->renderOAuthVerification(['error' => 'Invalid username or password']);
            }

            $_SESSION['oauth_user'] = $result;
            return $this->renderConsentScreen();
        } else {
            $_SESSION['oauth_verification_mode'] = true;

            if ($provider === 'google') {
                return $this->redirectToGoogle();
            } elseif ($provider === 'linkedin') {
                return $this->redirectToLinkedin();
            } elseif ($provider === 'github') {
                return $this->redirectToGithub();
            }
        }

        return $this->renderOAuthVerification(['error' => 'Invalid authentication method']);
    }

    /**
     * Handle Google OAuth verify callback
     */
    public function handleGoogleVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;

        if (!$code) {
            return $this->renderOAuthVerification(['error' => 'Google authentication failed']);
        }

        $result = $this->verifyGoogleCallback($code);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'Google authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }

    /**
     * Handle LinkedIn OAuth verify callback
     */
    public function handleLinkedinVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication failed']);
        }

        $result = $this->verifyLinkedinCallback($code, $state);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }

    /**
     * Handle GitHub OAuth verify callback
     */
    public function handleGithubVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication failed']);
        }

        $result = $this->verifyGithubCallback($code, $state);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }

    /**
     * Handle consent screen submission
     */
    public function consent(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_request']) || !isset($_SESSION['oauth_user'])) {
            return $this->errorResponse('invalid_request', 'OAuth session expired');
        }

        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return $this->errorResponse('invalid_request', 'Invalid request data');
        }

        $action = $data['action'] ?? '';
        $oauthRequest = $_SESSION['oauth_request'];
        $oauthUser = $_SESSION['oauth_user'];

        if ($action === 'deny') {
            $this->cleanupOAuthSession();

            $query = http_build_query(
                [
                'error' => 'access_denied',
                'error_description' => 'User denied the request',
                'state' => $oauthRequest['state']
                ]
            );

            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $oauthRequest['redirect_uri'] . '?' . $query);
        }

        if ($action === 'allow') {
            $authCode = bin2hex(random_bytes(32));

            $this->storage->storeAuthorizationCode(
                $authCode,
                [
                'client_id' => $oauthRequest['client_id'],
                'redirect_uri' => $oauthRequest['redirect_uri'],
                'scope' => $oauthRequest['scope'],
                'expires_at' => time() + 300,
                'code_challenge' => $oauthRequest['code_challenge'],
                'code_challenge_method' => $oauthRequest['code_challenge_method'],
                'agency_id' => $oauthUser['agency_id'],
                'user_id' => $oauthUser['user_id']
                ]
            );

            $this->cleanupOAuthSession();

            $responseData = [
                'code' => $authCode,
                'state' => $oauthRequest['state'],
                'scope' => $oauthRequest['scope']
            ];

            if ($oauthRequest['redirect_uri'] === 'urn:ietf:wg:oauth:2.0:oob') {
                $html = "<!DOCTYPE html><html><head><title>Authorization Code</title></head>
                     <body><h1>Authorization Successful</h1>
                     <p>Copy this authorization code: <strong>{$authCode}</strong></p>
                     <p>Paste it back into your application to complete the connection.</p></body></html>";

                $stream = $this->streamFactory->createStream($html);
                return $this->responseFactory->createResponse(200)
                    ->withBody($stream)
                    ->withHeader('Content-Type', 'text/html');
            } else {
                $query = http_build_query($responseData);
                return $this->responseFactory->createResponse(302)
                    ->withHeader('Location', $oauthRequest['redirect_uri'] . '?' . $query);
            }
        }

        return $this->renderConsentScreen(['error' => 'Invalid action']);
    }

    /**
     * Token endpoint for exchanging auth code for access token
     */
    public function token(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $body = $request->getBody()->getContents();
            if (!empty($body)) {
                $data = json_decode($body, true);
            }
        }

        if (!is_array($data)) {
            return $this->errorResponse('invalid_request', 'Invalid request data');
        }

        $grantType = $data['grant_type'] ?? null;

        if ($grantType === 'authorization_code') {
            return $this->handleAuthorizationCodeGrant($data);
        } elseif ($grantType === 'refresh_token') {
            return $this->handleRefreshTokenGrant($data);
        }

        return $this->errorResponse('unsupported_grant_type', 'Unsupported grant type');
    }

    /**
     * Revoke token endpoint
     */
    public function revoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $body = $request->getBody()->getContents();
            if (!empty($body)) {
                $data = json_decode($body, true);
            }
        }

        if (!is_array($data)) {
            return $this->errorResponse('invalid_request', 'Invalid request data');
        }

        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->errorResponse('invalid_request', 'Missing token parameter');
        }

        $this->storage->revokeToken($token);

        $stream = $this->streamFactory->createStream('{}');
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Client registration endpoint
     */
    public function register(Request $request, Response $response): Response
    {
        $body = $request->getBody()->getContents();
        $data = null;

        if (is_string($body) && !empty($body)) {
            $data = json_decode($body, true);
        } else {
            $data = $request->getParsedBody();
        }

        if (!is_array($data)) {
            return $this->errorResponse('invalid_request', 'Invalid request data');
        }

        $requiredFields = ['client_name', 'redirect_uris'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return $this->errorResponse('invalid_request', "Missing {$field}");
            }
        }

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = null;

        $this->storage->storeOAuthClient(
            [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'client_name' => $data['client_name'],
            'redirect_uris' => $data['redirect_uris'],
            'grant_types' => $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'response_types' => $data['response_types'] ?? ['code']
            ]
        );

        $responseData = [
            'client_id' => $clientId,
            'client_name' => $data['client_name'],
            'grant_types' => $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'response_types' => $data['response_types'] ?? ['code']
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Initialize social auth providers from configuration
     */
    private function initializeSocialProviders(): void
    {
        if (isset($this->config['google'])) {
            $this->googleProvider = new GoogleProvider(
                $this->config['google']['client_id'],
                $this->config['google']['client_secret'],
                $this->config['google']['redirect_uri']
            );
        }

        if (isset($this->config['linkedin'])) {
            $this->linkedinProvider = new LinkedinProvider(
                $this->config['linkedin']['client_id'],
                $this->config['linkedin']['client_secret'],
                $this->config['linkedin']['redirect_uri']
            );
        }

        if (isset($this->config['github'])) {
            $this->githubProvider = new GithubProvider(
                $this->config['github']['client_id'],
                $this->config['github']['client_secret'],
                $this->config['github']['redirect_uri']
            );
        }
    }

    /**
     * Verify email/password credentials
     */
    private function verifyEmailPassword(string $email, string $password): ?array
    {
        try {
            $userData = $this->storage->verifyUserCredentials($email, $password);
            if (!$userData) {
                return null;
            }

            return [
                'user_id' => $userData['user_id'],
                'agency_id' => $userData['agency_id'],
                'name' => $userData['name'],
                'email' => $userData['email']
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Redirect to Google OAuth
     */
    private function redirectToGoogle(): Response
    {
        if (!$this->googleProvider) {
            return $this->renderOAuthVerification(['error' => 'Google authentication not configured']);
        }

        $authUrl = $this->googleProvider->getAuthUrl();
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Redirect to LinkedIn OAuth
     */
    private function redirectToLinkedin(): Response
    {
        if (!$this->linkedinProvider) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication not configured']);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $authUrl = $this->linkedinProvider->getAuthUrl($state);
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Redirect to GitHub OAuth
     */
    private function redirectToGithub(): Response
    {
        if (!$this->githubProvider) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication not configured']);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $authUrl = $this->githubProvider->getAuthUrl($state);
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Verify Google OAuth callback
     */
    private function verifyGoogleCallback(string $code): ?array
    {
        if (!$this->googleProvider) {
            return null;
        }

        try {
            $result = $this->googleProvider->handleCallback($code);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByGoogleId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verify LinkedIn OAuth callback
     */
    private function verifyLinkedinCallback(string $code, ?string $state): ?array
    {
        if (!$this->linkedinProvider) {
            return null;
        }

        try {
            $result = $this->linkedinProvider->handleCallback($code, $state);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByLinkedinId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verify GitHub OAuth callback
     */
    private function verifyGithubCallback(string $code, ?string $state): ?array
    {
        if (!$this->githubProvider) {
            return null;
        }

        try {
            $result = $this->githubProvider->handleCallback($code, $state);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByGithubId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find or create user by Google ID
     */
    private function findOrCreateUserByGoogleId(string $googleId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByGoogleId')) {
            $user = $this->storage->findUserByGoogleId($googleId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserGoogleId')) {
                $this->storage->updateUserGoogleId($user['id'], $googleId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }

    /**
     * Find or create user by LinkedIn ID
     */
    private function findOrCreateUserByLinkedinId(string $linkedinId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByLinkedinId')) {
            $user = $this->storage->findUserByLinkedinId($linkedinId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserLinkedinId')) {
                $this->storage->updateUserLinkedinId($user['id'], $linkedinId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }

    /**
     * Find or create user by GitHub ID
     */
    private function findOrCreateUserByGithubId(string $githubId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByGithubId')) {
            $user = $this->storage->findUserByGithubId($githubId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserGithubId')) {
                $this->storage->updateUserGithubId($user['id'], $githubId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }

    /**
     * Handle authorization code grant for token exchange
     */
    private function handleAuthorizationCodeGrant(array $data): Response
    {
        $code = $data['code'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $codeVerifier = $data['code_verifier'] ?? null;

        if (!$code || !$clientId) {
            return $this->errorResponse('invalid_request', 'Missing required parameters');
        }

        $authCode = $this->storage->getAuthorizationCode($code, $clientId);
        if (!$authCode) {
            return $this->errorResponse('invalid_grant', 'Invalid or expired authorization code');
        }

        $client = $this->storage->getOAuthClient($clientId);
        if (!$client) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        if ($client['client_secret'] && $client['client_secret'] !== $clientSecret) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        if (isset($authCode['redirect_uri']) && $redirectUri !== $authCode['redirect_uri']) {
            return $this->errorResponse('invalid_grant', 'Invalid redirect_uri');
        }

        if (!$authCode['code_challenge']) {
            return $this->errorResponse('invalid_grant', 'Missing code_verifier');
        }

        if (!$codeVerifier) {
            return $this->errorResponse('invalid_grant', 'Missing code_verifier');
        }

        $method = $authCode['code_challenge_method'] ?? 'plain';
        $challenge = $method === 'S256'
            ? rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=')
            : $codeVerifier;

        if (!hash_equals($authCode['code_challenge'], $challenge)) {
            return $this->errorResponse('invalid_grant', 'Invalid code_verifier');
        }

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        $this->storage->revokeAuthorizationCode($code);

        $this->storage->storeAccessToken(
            [
            'client_id' => $clientId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'scope' => $authCode['scope'],
            'expires_at' => time() + 3600,
            'agency_id' => $authCode['agency_id'],
            'user_id' => $authCode['user_id']
            ]
        );

        $responseData = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken,
            'scope' => $authCode['scope']
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Handle refresh token grant
     */
    private function handleRefreshTokenGrant(array $data): Response
    {
        $refreshToken = $data['refresh_token'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;

        if (!$refreshToken || !$clientId) {
            return $this->errorResponse('invalid_request', 'Missing required parameters');
        }

        $client = $this->storage->getOAuthClient($clientId);
        if (!$client) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        if ($client['client_secret'] && $client['client_secret'] !== $clientSecret) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        $tokenData = $this->storage->getTokenByRefreshToken($refreshToken, $clientId);
        if (!$tokenData) {
            return $this->errorResponse('invalid_grant', 'Invalid refresh token');
        }

        if (isset($tokenData['revoked']) && $tokenData['revoked']) {
            return $this->errorResponse('invalid_grant', 'Invalid refresh token');
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));

        $this->storage->revokeToken($tokenData['access_token']);
        $this->storage->revokeToken($refreshToken);

        $this->storage->storeAccessToken(
            [
            'client_id' => $clientId,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'scope' => $tokenData['scope'],
            'expires_at' => time() + 3600,
            'agency_id' => $tokenData['agency_id'],
            'user_id' => $tokenData['user_id']
            ]
        );

        $responseData = [
            'access_token' => $newAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $newRefreshToken,
            'scope' => $tokenData['scope']
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Validate OAuth state parameter
     */
    private function validateState(string $state): bool
    {
        return isset($_SESSION['oauth_state']) && hash_equals($_SESSION['oauth_state'], $state);
    }

    /**
     * Clean up OAuth session data
     */
    private function cleanupOAuthSession(): void
    {
        unset($_SESSION['oauth_request'], $_SESSION['oauth_user'], $_SESSION['oauth_state'], $_SESSION['oauth_verification_mode']);
    }

    /**
     * Render OAuth verification form
     */
    private function renderOAuthVerification(array $data = []): Response
    {
        $clientName = $_SESSION['oauth_request']['client_name'] ?? 'Unknown Application';
        $error = $data['error'] ?? '';

        $socialButtons = '';
        if ($this->googleProvider || $this->linkedinProvider || $this->githubProvider) {
            $socialButtons = '<form method="POST" action="/oauth/verify">';

            if ($this->googleProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="google" class="btn social google">Continue with Google</button>';
            }
            if ($this->linkedinProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="linkedin" class="btn social linkedin">Continue with LinkedIn</button>';
            }
            if ($this->githubProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="github" class="btn social github">Continue with GitHub</button>';
            }

            $socialButtons .= '</form><div class="divider"><span>or</span></div>';
        }

        $html = "<!DOCTYPE html>
<html>
<head>
    <title>Authorize {$clientName}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; background: #f9f9f9; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #d73a49; background: #ffeef0; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #fdbdbd; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #24292e; }
        input[type=email], input[type=password] { width: 100%; padding: 12px; border: 1px solid #d1d5da; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; margin-bottom: 8px; }
        .primary { background: #0366d6; color: white; }
        .social { background: #f6f8fa; color: #24292e; border: 1px solid #d1d5da; }
        .google { background: #4285f4; color: white; border: none; }
        .linkedin { background: #0077b5; color: white; border: none; }
        .github { background: #24292e; color: white; border: none; }
        .divider { text-align: center; margin: 20px 0; color: #6a737d; position: relative; }
        .divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #e1e4e8; }
        .divider span { background: white; padding: 0 16px; }
        h1 { color: #24292e; margin-bottom: 24px; font-size: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Authorize {$clientName}</h1>
        " . ($error ? "<div class='error'>{$error}</div>" : "") . "

        {$socialButtons}

        <form method='POST' action='/oauth/verify'>
            <div class='form-group'>
                <label>Email</label>
                <input type='email' name='email' required>
            </div>
            <div class='form-group'>
                <label>Password</label>
                <input type='password' name='password' required>
            </div>
            <button type='submit' name='provider' value='email' class='btn primary'>Sign In</button>
        </form>
    </div>
</body>
</html>";

        $stream = $this->streamFactory->createStream($html);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/html');
    }

    /**
     * Render consent screen
     */
    private function renderConsentScreen(array $data = []): Response
    {
        $oauthRequest = $_SESSION['oauth_request'];
        $oauthUser = $_SESSION['oauth_user'];

        $clientName = $oauthRequest['client_name'];
        $userName = $oauthUser['name'] ?? $oauthUser['email'];
        $userEmail = $oauthUser['email'];
        $scope = $oauthRequest['scope'];
        $error = $data['error'] ?? '';

        $html = "<!DOCTYPE html>
<html>
<head>
    <title>Authorize {$clientName}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; background: #f9f9f9; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #d73a49; background: #ffeef0; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
        .user-info { background: #f6f8fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #d1d5da; }
        .permissions { margin: 20px 0; }
        .btn { padding: 12px 24px; margin: 5px; border: none; cursor: pointer; border-radius: 4px; font-weight: 500; }
        .allow { background: #28a745; color: white; }
        .deny { background: #dc3545; color: white; }
        h1 { color: #24292e; margin-bottom: 24px; font-size: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Authorize {$clientName}</h1>
        " . ($error ? "<div class='error'>{$error}</div>" : "") . "
        <div class='user-info'>
            <strong>Signed in as:</strong> {$userName}<br>
            <small>{$userEmail}</small>
        </div>
        <div class='permissions'>
            <p><strong>{$clientName}</strong> is requesting access to:</p>
            <ul>
                <li>Access your MCP server data ({$scope})</li>
            </ul>
        </div>
        <form method='POST' action='/oauth/consent'>
            <button type='submit' name='action' value='allow' class='btn allow'>Allow</button>
            <button type='submit' name='action' value='deny' class='btn deny'>Deny</button>
        </form>
    </div>
</body>
</html>";

        $stream = $this->streamFactory->createStream($html);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/html');
    }

    /**
     * Generate OAuth error response
     */
    private function errorResponse(string $error, string $description = ''): Response
    {
        $data = ['error' => $error];
        if ($description) {
            $data['error_description'] = $description;
        }

        $jsonContent = json_encode($data);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(400)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'base_url' => 'https://localhost',
            'session_lifetime' => 3600
        ];
    }
}
