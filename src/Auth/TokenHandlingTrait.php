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
        $resource = $data['resource'] ?? null; // RFC 8707

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

        // Store access token with resource binding if present
        $tokenData = [
            'client_id' => $clientId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'scope' => $authCode['scope'],
            'expires_at' => time() + 3600,
            'agency_id' => $authCode['agency_id'],
            'user_id' => $authCode['user_id']
        ];

        // Add resource binding from authorization code (authoritative source)
        if (isset($authCode['resource'])) {
            $tokenData['resource'] = $authCode['resource'];
            $tokenData['aud'] = [$authCode['resource']]; // Audience claim for token validation
        }

        if (!$this->storage->storeAccessToken($tokenData)) {
            return $this->errorResponse('server_error', 'Failed to store access token');
        }

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
     * Handle refresh token grant with resource binding preservation
     */
    private function handleRefreshTokenGrant(array $data, Request $request): Response
    {
        $refreshToken = $data['refresh_token'] ?? null;
        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $resource = $data['resource'] ?? null; // RFC 8707 - optional validation parameter

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

        // Validate client-passed resource parameter against stored token data
        if ($resource !== null && isset($tokenData['resource'])) {
            if ($resource !== $tokenData['resource']) {
                return $this->errorResponse('invalid_grant', 'Resource parameter must match token binding');
            }
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));

        $this->storage->revokeToken($tokenData['access_token']);
        $this->storage->revokeToken($refreshToken);

        // Preserve all metadata from original token - only change tokens and expiration
        $newTokenData = [
            'client_id' => $clientId,
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'scope' => $tokenData['scope'],
            'expires_at' => time() + 3600,
            'agency_id' => $tokenData['agency_id'],
            'user_id' => $tokenData['user_id']
        ];

        // Preserve resource binding metadata without modification
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
}
