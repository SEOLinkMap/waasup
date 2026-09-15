<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait TokenHandlingTrait
{
    /**
     * Handle authorization code grant with RFC 8707 Resource Indicators
     */
    private function handleAuthorizationCodeGrant(array $data, Request $request): Response
    {
        $code = $data['code'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $redirectUri = $data['redirect_uri'] ?? null;
        $codeVerifier = $data['code_verifier'] ?? null;
        $resource = $data['resource'] ?? null;

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

        if (!$this->clientSecretMatches($client, $clientSecret)) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        if (isset($authCode['redirect_uri']) && $redirectUri !== $authCode['redirect_uri']) {
            return $this->errorResponse('invalid_grant', 'Invalid redirect_uri');
        }

        if (!$authCode['code_challenge']) {
            return $this->errorResponse('invalid_grant', 'Authorization code was issued without PKCE. Restart the flow with code_challenge and code_challenge_method=S256.');
        }

        if (!$codeVerifier) {
            return $this->errorResponse('invalid_grant', 'Missing code_verifier');
        }

        if (($authCode['code_challenge_method'] ?? '') !== 'S256') {
            return $this->errorResponse('invalid_grant', 'Unsupported code_challenge_method. This server requires S256.');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        if (!hash_equals($authCode['code_challenge'], $challenge)) {
            return $this->errorResponse('invalid_grant', 'Invalid code_verifier');
        }

        if (!$this->storage->revokeAuthorizationCode($code)) {
            return $this->errorResponse('invalid_grant', 'Authorization code was already redeemed. Start a new authorization request.');
        }

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $accessTokenLifetime = (int)$this->config['oauth']['access_token_lifetime'];

        $tokenData = [
            'client_id' => $clientId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'scope' => $authCode['scope'],
            'expires_at' => time() + $accessTokenLifetime,
            'agency_id' => $authCode['agency_id'],
            'user_id' => $authCode['user_id']
        ];

        if (isset($authCode['resource'])) {
            $tokenData['resource'] = $authCode['resource'];
            $tokenData['aud'] = [$authCode['resource']];
        }

        if (!$this->storage->storeAccessToken($tokenData)) {
            return $this->errorResponse('server_error', 'Failed to store access token');
        }

        $responseData = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenLifetime,
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
     * Handle refresh token grant with resource binding preservation
     */
    private function handleRefreshTokenGrant(array $data, Request $request): Response
    {
        $refreshToken = $data['refresh_token'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $resource = $data['resource'] ?? null;

        if (!$refreshToken || !$clientId) {
            return $this->errorResponse('invalid_request', 'Missing required parameters');
        }

        $client = $this->storage->getOAuthClient($clientId);
        if (!$client) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        if (!$this->clientSecretMatches($client, $clientSecret)) {
            return $this->errorResponse('invalid_client', 'Invalid client credentials');
        }

        $tokenData = $this->storage->getTokenByRefreshToken($refreshToken, $clientId);
        if (!$tokenData) {
            $this->storage->revokeTokenFamily($refreshToken);

            return $this->errorResponse('invalid_grant', 'Invalid refresh token. It may already have been used; authorize again to obtain a new one.');
        }

        if (isset($tokenData['revoked']) && $tokenData['revoked']) {
            return $this->errorResponse('invalid_grant', 'Invalid refresh token');
        }

        if ($this->isRefreshTokenExpired($tokenData)) {
            $this->storage->revokeToken($refreshToken);
            return $this->errorResponse('invalid_grant', 'Expired refresh token');
        }

        if ($resource !== null && isset($tokenData['resource'])) {

            if ($resource !== $tokenData['resource']) {
                return $this->errorResponse('invalid_grant', 'Resource parameter must match token binding');
            }
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));
        $accessTokenLifetime = (int)$this->config['oauth']['access_token_lifetime'];

        $this->storage->revokeToken($tokenData['access_token']);
        $this->storage->revokeToken($refreshToken);

        $newTokenData = [
            'client_id' => $clientId,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'scope' => $tokenData['scope'],
            'expires_at' => time() + $accessTokenLifetime,
            'agency_id' => $tokenData['agency_id'],
            'user_id' => $tokenData['user_id']
        ];

        if (isset($tokenData['resource'])) {
            $newTokenData['resource'] = $tokenData['resource'];
        }
        if (isset($tokenData['aud'])) {
            $newTokenData['aud'] = $tokenData['aud'];
        }

        if (!$this->storage->storeAccessToken($newTokenData)) {
            return $this->errorResponse('server_error', 'Failed to store access token');
        }

        $responseData = [
            'access_token' => $newAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenLifetime,
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
     * Determine whether a refresh token has outlived the configured lifetime
     *
     * @param array $tokenData stored token row
     * @return bool true when the refresh token must be rejected
     */
    private function isRefreshTokenExpired(array $tokenData): bool
    {
        $lifetime = $this->config['oauth']['refresh_token_lifetime'];

        if ($lifetime === null) {
            return false;
        }

        $issuedAt = $this->normalizeTimestamp($tokenData['created_at'] ?? null);

        if ($issuedAt === null) {
            return false;
        }

        return ($issuedAt + (int)$lifetime) <= time();
    }

    /**
     * Convert a stored timestamp to a unix timestamp
     *
     * @param mixed $value unix timestamp or datetime string
     * @return int|null unix timestamp, or null when unreadable
     */
    private function normalizeTimestamp($value): ?int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
