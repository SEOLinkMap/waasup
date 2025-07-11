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
}
