<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait UtilityTrait
{
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
     * Get base URL from request
     */
    private function getBaseUrl(Request $request): string
    {

        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() .
               ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    /**
     * Validate OAuth state parameter
     */
    private function validateState(?string $state): bool
    {
        return $state !== null && isset($_SESSION['oauth_state']) && hash_equals($_SESSION['oauth_state'], $state);
    }

    /**
     * Validate the CSRF token posted back by an OAuth form
     *
     * @return bool true when it matches the token issued with this OAuth request
     */
    private function validateCsrfToken(?string $token): bool
    {
        return $token !== null && isset($_SESSION['oauth_csrf']) && hash_equals($_SESSION['oauth_csrf'], $token);
    }

    /**
     * Compare a presented client secret against the registered one
     *
     * @param array $client registered client row
     * @return bool true when the client authenticated, or registered without a secret
     */
    private function clientSecretMatches(array $client, ?string $presented): bool
    {
        if (empty($client['client_secret'])) {
            return true;
        }

        return $presented !== null && hash_equals((string)$client['client_secret'], $presented);
    }

    /**
     * Clean up OAuth session data
     */
    private function cleanupOAuthSession(): void
    {
        unset($_SESSION['oauth_request'], $_SESSION['oauth_user'], $_SESSION['oauth_state'], $_SESSION['oauth_verification_mode']);
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

        $status = match ($error) {
            'invalid_token' => 401,
            'insufficient_scope' => 403,
            'invalid_client' => 401,
            'unauthorized_client' => 401,
            'invalid_grant' => 400,
            'unsupported_grant_type' => 400,
            'invalid_request' => 400,
            'unsupported_response_type' => 400,
            'server_error' => 500,
            default => 400
        };

        $wwwAuth = 'Bearer realm="OAuth Server"';
        if ($error) {
            $wwwAuth .= ', error="' . $error . '"';
            if ($description) {
                $wwwAuth .= ', error_description="' . $description . '"';
            }
        }

        $jsonContent = json_encode($data);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"invalid_request","error_description":"JSON encoding failed"}';
        }

        $stream = $this->streamFactory->createStream($jsonContent);

        return $this->responseFactory->createResponse($status)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', $wwwAuth);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'base_url' => null,
            'session_user_id' => null,
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'oauth' => [
                'access_token_lifetime' => 3600,
                'refresh_token_lifetime' => null,
                'authorization_code_lifetime' => 300,
                'ui' => [
                    'background_color' => null,
                    'text_color' => null,
                    'accent_color' => null
                ],
                'auth_server' => [
                    'endpoints' => [
                        'authorize' => '/oauth/authorize',
                        'token' => '/oauth/token',
                        'verify' => '/oauth/verify',
                        'consent' => '/oauth/consent',
                        'register' => '/oauth/register',
                        'revoke' => '/oauth/revoke'
                    ],
                    'providers' => [
                        'google' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ],
                        'linkedin' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ],
                        'github' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ]
                    ]
                ]
            ]
        ];
    }
}
