<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait OAuthEndpointsTrait
{
    /**
     * OAuth 2.1 authorization endpoint with PKCE and resource indicators
     *
     * @param Request $request
     * @param Response $response
     * @return Response Auth form or error response
     */
    public function authorize(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $clientId = $params['client_id'] ?? null;
        $redirectUri = $params['redirect_uri'] ?? null;
        $scope = $params['scope'] ?? 'mcp:read mcp:write';
        $state = $params['state'] ?? null;
        $responseType = $params['response_type'] ?? null;
        $codeChallenge = $params['code_challenge'] ?? null;
        $codeChallengeMethod = $params['code_challenge_method'] ?? null;
        $resource = $params['resource'] ?? null;

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

        if (!$codeChallenge) {
            return $this->errorResponse('invalid_request', 'Missing code_challenge parameter. This server requires PKCE with code_challenge_method=S256.');
        }

        if ($codeChallengeMethod !== 'S256') {
            return $this->errorResponse('invalid_request', 'Unsupported code_challenge_method. This server requires S256.');
        }

        $supportedScopes = $this->config['scopes_supported'];
        $unknownScopes = array_diff(explode(' ', trim($scope)), $supportedScopes);

        if (!empty($unknownScopes)) {
            return $this->errorResponse('invalid_scope', 'Unsupported scope: ' . implode(' ', $unknownScopes) . '. This server issues: ' . implode(' ', $supportedScopes) . '.');
        }

        $expectedBaseUrl = $this->getBaseUrl($request);

        if (!empty($resource) && !str_starts_with($resource, $expectedBaseUrl)) {
            return $this->errorResponse('invalid_request', 'Resource parameter must be for this resource server');
        }

        if (!empty($resource)) {
            $parsedResource = parse_url($resource);
            if (
                !$parsedResource ||
                !empty($parsedResource['fragment']) ||
                str_contains($resource, '..')
            ) {
                return $this->errorResponse('invalid_request', 'Invalid resource URL format');
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['oauth_csrf'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_request'] = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'client_name' => $clientData['client_name'],
            'resource' => $resource
        ];

        $sessionUserIdKey = $this->config['session_user_id'];
        if ($sessionUserIdKey !== null && isset($_SESSION[$sessionUserIdKey]) && $_SESSION[$sessionUserIdKey]) {
            $userData = $this->storage->getUserData($_SESSION[$sessionUserIdKey]);
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
     * Handle OAuth verification form submission (email/password or social auth)
     *
     * @param Request $request
     * @param Response $response
     * @return Response Consent screen, auth form with error, or social redirect
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

        if (!$this->validateCsrfToken($data['csrf_token'] ?? null)) {
            return $this->errorResponse('invalid_request', 'Form token missing or stale. Reload the authorization link and sign in again.');
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
     * Handle consent screen submission (allow/deny)
     *
     * @param Request $request
     * @param Response $response
     * @return Response Redirect with auth code or error
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

        if (!$this->validateCsrfToken($data['csrf_token'] ?? null)) {
            return $this->errorResponse('invalid_request', 'Form token missing or stale. Reload the authorization link and sign in again.');
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

            $authCodeData = [
                'client_id' => $oauthRequest['client_id'],
                'redirect_uri' => $oauthRequest['redirect_uri'],
                'scope' => $oauthRequest['scope'],
                'expires_at' => time() + (int)$this->config['oauth']['authorization_code_lifetime'],
                'code_challenge' => $oauthRequest['code_challenge'],
                'code_challenge_method' => $oauthRequest['code_challenge_method'],
                'agency_id' => $oauthUser['agency_id'],
                'user_id' => $oauthUser['user_id']
            ];

            if (isset($oauthRequest['resource'])) {
                $authCodeData['resource'] = $oauthRequest['resource'];
            }

            $this->storage->storeAuthorizationCode($authCode, $authCodeData);

            $this->cleanupOAuthSession();

            $responseData = [
                'code' => $authCode,
                'state' => $oauthRequest['state'],
                'scope' => $oauthRequest['scope'],
                'iss' => $this->getBaseUrl($request)
            ];

            if ($oauthRequest['redirect_uri'] === 'urn:ietf:wg:oauth:2.0:oob') {
                return $this->renderAuthorizationCode($authCode);
            } else {
                $query = http_build_query($responseData);
                return $this->responseFactory->createResponse(302)
                    ->withHeader('Location', $oauthRequest['redirect_uri'] . '?' . $query);
            }
        }

        return $this->renderConsentScreen(['error' => 'Invalid action']);
    }

    /**
     * OAuth 2.1 token endpoint for authorization_code and refresh_token grants
     *
     * @param Request $request
     * @param Response $response
     * @return Response JSON with access_token or error
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
            return $this->handleAuthorizationCodeGrant($data, $request);
        } elseif ($grantType === 'refresh_token') {
            return $this->handleRefreshTokenGrant($data, $request);
        }

        return $this->errorResponse('unsupported_grant_type', 'Unsupported grant type');
    }

    /**
     * Token revocation endpoint
     *
     * @param Request $request
     * @param Response $response
     * @return Response Empty 200 response
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
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;

        if (!$token) {
            return $this->errorResponse('invalid_request', 'Missing token parameter');
        }

        if (!$clientId) {
            return $this->errorResponse('invalid_client', 'Missing client_id parameter');
        }

        $client = $this->storage->getOAuthClient($clientId);

        if (!$client || !$this->clientSecretMatches($client, $clientSecret)) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        $this->storage->revokeToken($token);

        $stream = $this->streamFactory->createStream('{}');
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Dynamic client registration endpoint
     *
     * @param Request $request
     * @param Response $response
     * @return Response JSON with client_id or error
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
        $clientSecret = bin2hex(random_bytes(32));

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
            'client_id_issued_at' => time(),
            'client_name' => $data['client_name'],
            'client_secret' => $clientSecret,
            'client_secret_expires_at' => 0,
            'redirect_uris' => $data['redirect_uris'],
            'grant_types' => $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'response_types' => $data['response_types'] ?? ['code']
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(201)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }
}
