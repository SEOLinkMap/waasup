<?php

namespace Seolinkmap\Waasup\Storage;

/**
 * Storage interface for MCP server data persistence
 */
interface StorageInterface
{
    /**
     * Store a message for SSE delivery
     */
    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool;

    /**
     * Retrieve pending messages for a session
     */
    public function getMessages(string $sessionId, array $context = []): array;

    /**
     * Delete a message after delivery
     */
    public function deleteMessage(string $messageId): bool;

    /**
     * Validate OAuth token
     */
    public function validateToken(string $accessToken, array $context = []): ?array;

    /**
     * Get context data by identifier (agency, user, etc.)
     */
    public function getContextData(string $identifier, string $type = 'agency'): ?array;

    /**
     * Store session data
     */
    public function storeSession(string $sessionId, array $sessionData, int $ttl = 3600): bool;

    /**
     * Get session data
     */
    public function getSession(string $sessionId): ?array;

    /**
     * Clean up expired sessions and messages
     */
    public function cleanup(): int;

    /**
     * Get OAuth client by client ID
     */
    public function getOAuthClient(string $clientId): ?array;

    /**
     * Store OAuth client registration
     */
    public function storeOAuthClient(array $clientData): bool;

    /**
     * Store authorization code for OAuth flow
     */
    public function storeAuthorizationCode(string $code, array $codeData): bool;

    /**
     * Get authorization code for token exchange
     */
    public function getAuthorizationCode(string $code, string $clientId): ?array;

    /**
     * Revoke authorization code after use
     */
    public function revokeAuthorizationCode(string $code): bool;

    /**
     * Store access token
     */
    public function storeAccessToken(array $tokenData): bool;

    /**
     * Get token data by refresh token
     */
    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array;

    /**
     * Revoke access or refresh token
     */
    public function revokeToken(string $token): bool;

    /**
     * Get user data by user ID
     */
    public function getUserData(int $userId): ?array;

    /**
     * Verify user email/password credentials
     */
    public function verifyUserCredentials(string $email, string $password): ?array;

    /**
     * Find user by email address
     */
    public function findUserByEmail(string $email): ?array;

    /**
     * Store sampling response from client
     */
    public function storeSamplingResponse(string $sessionId, string $requestId, array $responseData): bool;

    /**
     * Get sampling response by request ID
     */
    public function getSamplingResponse(string $sessionId, string $requestId): ?array;

    /**
     * Get all pending sampling responses for a session
     */
    public function getSamplingResponses(string $sessionId): array;

    /**
     * Store roots response from client
     */
    public function storeRootsResponse(string $sessionId, string $requestId, array $responseData): bool;

    /**
     * Get roots response by request ID
     */
    public function getRootsResponse(string $sessionId, string $requestId): ?array;

    /**
     * Get all pending roots responses for a session
     */
    public function getRootsResponses(string $sessionId): array;

    /**
     * Store elicitation response from client
     */
    public function storeElicitationResponse(string $sessionId, string $requestId, array $responseData): bool;

    /**
     * Get elicitation response by request ID
     */
    public function getElicitationResponse(string $sessionId, string $requestId): ?array;

    /**
     * Get all pending elicitation responses for a session
     */
    public function getElicitationResponses(string $sessionId): array;
}
