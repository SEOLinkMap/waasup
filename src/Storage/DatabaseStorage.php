<?php

namespace Seolinkmap\Waasup\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Database storage implementation for MCP server data persistence
 *
 * PREFERRED USAGE: Use default table names with optional prefix
 * ================================================================
 * The simplest and recommended approach is to let this class create the default
 * table names, optionally with a prefix:
 *
 * Example (recommended):
 * ```php
 * $config = ['table_prefix' => 'mcp_'];
 * $storage = new DatabaseStorage($pdo, $config);
 * // Results in tables: mcp_agencies, mcp_users, mcp_oauth_clients, etc.
 * ```
 *
 * ADVANCED USAGE: Map to existing tables (only when you have existing data)
 * =========================================================================
 * Only use table_mapping when you have existing tables with data that you want
 * to integrate with. You'll need to ensure your existing tables have the required
 * fields (see field requirements below).
 *
 * Example (advanced - only for existing tables with data):
 * ```php
 * $config = [
 *     'table_prefix' => '',  // Empty since using custom mappings
 *     'table_mapping' => [
 *         'agencies' => 'client_agency',    // Your existing agency table
 *         'users' => 'app_users',           // Your existing user table
 *         // Don't map oauth_clients - let it use default since you have no data
 *         // Don't map sessions - let it use default (these are always new)
 *     ]
 * ];
 * $storage = new DatabaseStorage($pdo, $config);
 * ```
 *
 * FIELD REQUIREMENTS:
 * ==================
 * This class expects specific field names. You may need to:
 * - Add missing fields to existing tables
 * - Create database views to map field names
 * - Modify your schema to match expected names
 *
 * Required fields by table:
 *
 * agencies: id (int), uuid (varchar), name (varchar), active (tinyint/boolean)
 * users: id (int), agency_id (int), name (varchar), email (varchar), password (varchar)
 * users (optional social): google_id (varchar), linkedin_id (varchar), github_id (varchar)
 * oauth_clients: client_id (varchar), client_secret (varchar), client_name (varchar),
 *                redirect_uris (json/text), grant_types (json/text), response_types (json/text), created_at (datetime)
 * oauth_tokens: client_id (varchar), access_token (varchar), refresh_token (varchar),
 *               token_type (varchar), scope (varchar), expires_at (datetime), agency_id (int),
 *               user_id (int), revoked (tinyint/boolean), created_at (datetime)
 * sessions: session_id (varchar), session_data (text), expires_at (datetime), created_at (datetime)
 * messages: id (int auto), session_id (varchar), message_data (text), context_data (text), created_at (datetime)
 * sampling_responses: id (int auto), session_id (varchar), request_id (varchar), response_data (text), created_at (datetime)
 * roots_responses: id (int auto), session_id (varchar), request_id (varchar), response_data (text), created_at (datetime)
 * elicitation_responses: id (int auto), session_id (varchar), request_id (varchar), response_data (text), created_at (datetime)
 */
class DatabaseStorage implements StorageInterface
{
    private \PDO $pdo;
    private LoggerInterface $logger;
    private string $tablePrefix;
    private array $config;
    private string $databaseType;

    /**
     * Initialize database storage
     *
     * @param \PDO $pdo Database connection
     * @param array $config Configuration array with optional keys:
     *                     - 'table_prefix': Prefix for default table names (default: 'mcp_')
     *                     - 'table_mapping': Map logical names to existing table names (use sparingly)
     *                     - 'cleanup_interval': Cleanup frequency in seconds (default: 3600)
     */
    public function __construct(\PDO $pdo, array $config = [])
    {
        $this->logger = $config['logger'] ?? new NullLogger();
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->tablePrefix = $this->config['table_prefix'];
        $this->databaseType = $this->detectDatabaseType();
    }

    /**
     * Resolve logical table name to actual database table name
     *
     * Resolution order:
     * 1. Check table_mapping config for custom override (use only for existing tables with data)
     * 2. Fall back to table_prefix + logical name (preferred approach)
     *
     * @param string $logicalTableName One of the valid logical table names
     * @return string The actual database table name to use
     * @throws \InvalidArgumentException If logical table name is not recognized
     */
    private function getTableName(string $logicalTableName): string
    {
        // Valid logical table names that can be mapped/overridden
        // Only add entries to table_mapping for tables where you have existing data
        $validTableNames = [
            'agencies',              // Main agency/organization table (commonly has existing data)
            'users',                 // User accounts table (commonly has existing data)
            'oauth_clients',         // OAuth client registrations (may have existing data)
            'oauth_tokens',          // OAuth access/refresh tokens and auth codes (may have existing data)
            'sessions',              // MCP session data (usually new - rarely needs mapping)
            'messages',              // MCP message queue (always new - rarely needs mapping)
            'sampling_responses',    // MCP sampling responses (always new - no mapping needed)
            'roots_responses',       // MCP roots responses (always new - no mapping needed)
            'elicitation_responses'  // MCP elicitation responses (always new - no mapping needed)
        ];

        // Validate the logical table name
        if (!in_array($logicalTableName, $validTableNames)) {
            throw new \InvalidArgumentException("Invalid logical table name: {$logicalTableName}. Valid names are: " . implode(', ', $validTableNames));
        }

        // Check if there's a custom table mapping for this logical table
        // Only use this if you have existing tables with data to preserve
        if (isset($this->config['table_mapping'][$logicalTableName])) {
            return $this->config['table_mapping'][$logicalTableName];
        }

        // Fall back to prefixed default table name
        // This is the preferred path for most use cases
        return $this->tablePrefix . $logicalTableName;
    }

    /**
     * Store a message for SSE/streaming delivery to MCP clients
     *
     * Required fields in messages table:
     * - session_id (varchar): MCP session identifier
     * - message_data (text): JSON-encoded message data
     * - context_data (text): JSON-encoded context information
     * - created_at (datetime): Message creation timestamp
     */
    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('messages')}`
                (`session_id`, `message_data`, `context_data`, `created_at`)
                VALUES (:session_id, :message_data, :context_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':message_data' => json_encode($messageData),
            ':context_data' => json_encode($context),
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Retrieve pending messages for a session (ordered by creation time)
     */
    public function getMessages(string $sessionId, array $context = []): array
    {
        $sql = "SELECT `id`, `message_data`, `context_data`, `created_at`
                FROM `{$this->getTableName('messages')}`
                WHERE `session_id` = :session_id
                ORDER BY `created_at` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => $row['id'],
                'data' => json_decode($row['message_data'], true),
                'context' => json_decode($row['context_data'], true),
                'created_at' => $row['created_at']
            ];
        }
        return $messages;
    }

    /**
     * Delete a message after successful delivery
     */
    public function deleteMessage(string $messageId): bool
    {
        $sql = "DELETE FROM `{$this->getTableName('messages')}` WHERE `id` = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $messageId]);
    }

    /**
     * Validate OAuth bearer token
     *
     * Required fields in oauth_tokens table:
     * - access_token (varchar): The token to validate
     * - expires_at (datetime): Token expiration time
     * - revoked (tinyint/boolean): Whether token is revoked
     * - token_type (varchar): Must be 'Bearer' for validation
     */
    public function validateToken(string $accessToken, array $context = []): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_tokens')}`
                WHERE `access_token` = :token
                AND `expires_at` > :current_time
                AND `revoked` = 0
                AND `token_type` = 'Bearer'
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':token' => $accessToken,
            ':current_time' => $this->getCurrentTimestamp()
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get context data by identifier (agency or user)
     *
     * Required fields:
     * - agencies table: id, uuid, name, active (plus any additional fields you have)
     * - users table: id, uuid, name, email (plus any additional fields you have)
     */
    public function getContextData(string $identifier, string $type = 'agency'): ?array
    {
        switch ($type) {
            case 'agency':
                $sql = "SELECT * FROM `{$this->getTableName('agencies')}`
                        WHERE `uuid` = :identifier AND `active` = 1 LIMIT 1";
                break;
            case 'user':
                $sql = "SELECT * FROM `{$this->getTableName('users')}`
                        WHERE `uuid` = :identifier LIMIT 1";
                break;
            default:
                return null;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Store MCP session data with TTL
     *
     * Required fields in sessions table:
     * - session_id (varchar): Unique session identifier
     * - session_data (text): JSON-encoded session data
     * - expires_at (datetime): Session expiration time
     * - created_at (datetime): Session creation time
     */
    public function storeSession(string $sessionId, array $sessionData, int $ttl = 3600): bool
    {
        $expiresAt = $this->getTimestampWithOffset($ttl);
        $createdAt = $this->getCurrentTimestamp();

        // 1% of session operations trigger garbage collection for performance
        if (random_int(0, 99) < 1) {
            $this->cleanup();
        }

        if ($this->databaseType === 'mysql') {
            // Use MySQL's ON DUPLICATE KEY UPDATE for efficient upserts
            $sql = "INSERT INTO `{$this->getTableName('sessions')}`
                    (`session_id`, `session_data`, `expires_at`, `created_at`)
                    VALUES (:session_id, :session_data, :expires_at, :created_at)
                    ON DUPLICATE KEY UPDATE
                    `session_data` = VALUES(`session_data`),
                    `expires_at` = VALUES(`expires_at`)";

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':session_id' => $sessionId,
                ':session_data' => json_encode($sessionData),
                ':expires_at' => $expiresAt,
                ':created_at' => $createdAt
            ]);
        } else {
            // Use database-agnostic upsert for other databases
            return $this->upsertSession($sessionId, $sessionData, $expiresAt, $createdAt);
        }
    }

    /**
     * Retrieve session data if not expired
     */
    public function getSession(string $sessionId): ?array
    {
        $sql = "SELECT `session_data` FROM `{$this->getTableName('sessions')}`
                WHERE `session_id` = :session_id
                AND `expires_at` > :current_time";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':current_time' => $this->getCurrentTimestamp()
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return json_decode($result['session_data'], true);
        }

        return null;
    }

    /**
     * Clean up expired sessions and old messages
     * Returns number of records cleaned up
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $currentTime = $this->getCurrentTimestamp();

        $sql = "DELETE FROM `{$this->getTableName('sessions')}`
                WHERE `expires_at` < :current_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':current_time' => $currentTime]);
        $cleaned += $stmt->rowCount();

        return $cleaned;
    }

    /**
     * Get OAuth client by client ID
     *
     * Required fields in oauth_clients table:
     * - client_id, client_secret, client_name, redirect_uris, grant_types, response_types, created_at
     */
    public function getOAuthClient(string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_clients')}` WHERE `client_id` = :client_id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':client_id' => $clientId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Store OAuth client registration
     */
    public function storeOAuthClient(array $clientData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('oauth_clients')}`
                (`client_id`, `client_secret`, `client_name`, `redirect_uris`, `grant_types`, `response_types`, `created_at`)
                VALUES (:client_id, :client_secret, :client_name, :redirect_uris, :grant_types, :response_types, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':client_id' => $clientData['client_id'],
            ':client_secret' => $clientData['client_secret'],
            ':client_name' => $clientData['client_name'],
            ':redirect_uris' => json_encode($clientData['redirect_uris']),
            ':grant_types' => json_encode($clientData['grant_types']),
            ':response_types' => json_encode($clientData['response_types']),
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Store authorization code for OAuth flow
     * Note: Reuses oauth_tokens table with token_type = 'authorization_code'
     */
    public function storeAuthorizationCode(string $code, array $data): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('oauth_tokens')}`
                (`client_id`, `access_token`, `token_type`, `scope`, `expires_at`, `revoked`,
                 `code_challenge`, `code_challenge_method`, `agency_id`, `user_id`, `created_at`)
                VALUES (:client_id, :auth_code, 'authorization_code', :scope,
                        :expires_at, 0, :code_challenge, :code_challenge_method,
                        :agency_id, :user_id, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':client_id' => $data['client_id'],
            ':auth_code' => $code,
            ':scope' => $data['scope'],
            ':expires_at' => date('Y-m-d H:i:s', $data['expires_at']),
            ':code_challenge' => $data['code_challenge'],
            ':code_challenge_method' => $data['code_challenge_method'],
            ':agency_id' => $data['agency_id'],
            ':user_id' => $data['user_id'],
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Get authorization code for token exchange (OAuth flow)
     */
    public function getAuthorizationCode(string $code, string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_tokens')}`
                WHERE `access_token` = :code
                AND `client_id` = :client_id
                AND `token_type` = 'authorization_code'
                AND `expires_at` > :current_time
                AND `revoked` = 0
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $code,
            ':client_id' => $clientId,
            ':current_time' => $this->getCurrentTimestamp()
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Revoke authorization code after use (one-time use)
     */
    public function revokeAuthorizationCode(string $code): bool
    {
        $sql = "UPDATE `{$this->getTableName('oauth_tokens')}` SET `revoked` = 1 WHERE `access_token` = :code";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':code' => $code]);
    }

    /**
     * Store OAuth access token and refresh token
     */
    public function storeAccessToken(array $tokenData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('oauth_tokens')}`
                (`client_id`, `access_token`, `refresh_token`, `token_type`, `scope`, `expires_at`,
                 `agency_id`, `user_id`, `revoked`, `created_at`)
                VALUES (:client_id, :access_token, :refresh_token, 'Bearer', :scope,
                        :expires_at, :agency_id, :user_id, 0, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':client_id' => $tokenData['client_id'],
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'],
            ':scope' => $tokenData['scope'],
            ':expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
            ':agency_id' => $tokenData['agency_id'],
            ':user_id' => $tokenData['user_id'],
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Get token data by refresh token (for refresh flow)
     */
    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_tokens')}`
                WHERE `refresh_token` = :refresh_token
                AND `client_id` = :client_id
                AND `revoked` = 0
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':refresh_token' => $refreshToken,
            ':client_id' => $clientId
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Revoke access or refresh token
     */
    public function revokeToken(string $token): bool
    {
        $sql = "UPDATE `{$this->getTableName('oauth_tokens')}`
                SET `revoked` = 1
                WHERE (`access_token` = :token OR `refresh_token` = :token)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token' => $token]);
    }

    /**
     * Get user data by user ID
     *
     * Required fields in users table: id, agency_id, name, email
     */
    public function getUserData(int $userId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.id = :user_id LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Verify user email/password credentials
     *
     * Required fields in users table: id, password, agency_id, name, email
     */
    public function verifyUserCredentials(string $email, string $password): ?array
    {
        $sql = "SELECT u.id, u.password, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.email = :email
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        return [
            'user_id' => $user['id'],
            'agency_id' => $user['agency_id'],
            'name' => $user['name'],
            'email' => $user['email']
        ];
    }

    /**
     * Find user by Google OAuth ID
     * Optional field: google_id (varchar) in users table
     */
    public function findUserByGoogleId(string $googleId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.google_id = :google_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':google_id' => $googleId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find user by LinkedIn OAuth ID
     * Optional field: linkedin_id (varchar) in users table
     */
    public function findUserByLinkedinId(string $linkedinId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.linkedin_id = :linkedin_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':linkedin_id' => $linkedinId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find user by GitHub OAuth ID
     * Optional field: github_id (varchar) in users table
     */
    public function findUserByGithubId(string $githubId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.github_id = :github_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':github_id' => $githubId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find user by email address
     */
    public function findUserByEmail(string $email): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->getTableName('users')}` u
                WHERE u.email = :email
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Link user account to Google OAuth ID
     */
    public function updateUserGoogleId(int $userId, string $googleId): bool
    {
        $sql = "UPDATE `{$this->getTableName('users')}` SET `google_id` = :google_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':google_id' => $googleId, ':user_id' => $userId]);
    }

    /**
     * Link user account to LinkedIn OAuth ID
     */
    public function updateUserLinkedinId(int $userId, string $linkedinId): bool
    {
        $sql = "UPDATE `{$this->getTableName('users')}` SET `linkedin_id` = :linkedin_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':linkedin_id' => $linkedinId, ':user_id' => $userId]);
    }

    /**
     * Link user account to GitHub OAuth ID
     */
    public function updateUserGithubId(int $userId, string $githubId): bool
    {
        $sql = "UPDATE `{$this->getTableName('users')}` SET `github_id` = :github_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':github_id' => $githubId, ':user_id' => $userId]);
    }

    /**
     * Store sampling response from MCP client
     * Used for client-to-server communication in MCP protocol
     */
    public function storeSamplingResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('sampling_responses')}`
            (`session_id`, `request_id`, `response_data`, `created_at`)
            VALUES (:session_id, :request_id, :response_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId,
            ':response_data' => json_encode($responseData),
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Get sampling response by request ID
     */
    public function getSamplingResponse(string $sessionId, string $requestId): ?array
    {
        $sql = "SELECT `response_data`, `created_at` FROM `{$this->getTableName('sampling_responses')}`
            WHERE `session_id` = :session_id AND `request_id` = :request_id
            ORDER BY `created_at` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
                'data' => json_decode($result['response_data'], true),
                'created_at' => $result['created_at']
            ];
        }

        return null;
    }

    /**
     * Get all sampling responses for a session
     */
    public function getSamplingResponses(string $sessionId): array
    {
        $sql = "SELECT `request_id`, `response_data`, `created_at`
            FROM `{$this->getTableName('sampling_responses')}`
            WHERE `session_id` = :session_id
            ORDER BY `created_at` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row['request_id'],
                'data' => json_decode($row['response_data'], true),
                'created_at' => $row['created_at']
            ];
        }

        return $responses;
    }

    /**
     * Store roots response from MCP client
     * Used for file system access responses in MCP protocol
     */
    public function storeRootsResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('roots_responses')}`
            (`session_id`, `request_id`, `response_data`, `created_at`)
            VALUES (:session_id, :request_id, :response_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId,
            ':response_data' => json_encode($responseData),
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Get roots response by request ID
     */
    public function getRootsResponse(string $sessionId, string $requestId): ?array
    {
        $sql = "SELECT `response_data`, `created_at` FROM `{$this->getTableName('roots_responses')}`
            WHERE `session_id` = :session_id AND `request_id` = :request_id
            ORDER BY `created_at` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
                'data' => json_decode($result['response_data'], true),
                'created_at' => $result['created_at']
            ];
        }

        return null;
    }

    /**
     * Get all roots responses for a session
     */
    public function getRootsResponses(string $sessionId): array
    {
        $sql = "SELECT `request_id`, `response_data`, `created_at`
            FROM `{$this->getTableName('roots_responses')}`
            WHERE `session_id` = :session_id
            ORDER BY `created_at` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row['request_id'],
                'data' => json_decode($row['response_data'], true),
                'created_at' => $row['created_at']
            ];
        }

        return $responses;
    }

    /**
     * Store elicitation response from MCP client
     * Used for structured data elicitation responses in MCP protocol
     */
    public function storeElicitationResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('elicitation_responses')}`
                (`session_id`, `request_id`, `response_data`, `created_at`)
                VALUES (:session_id, :request_id, :response_data, :created_at)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId,
            ':response_data' => json_encode($responseData),
            ':created_at' => $this->getCurrentTimestamp()
        ]);
    }

    /**
     * Get elicitation response by request ID
     */
    public function getElicitationResponse(string $sessionId, string $requestId): ?array
    {
        $sql = "SELECT `response_data`, `created_at` FROM `{$this->getTableName('elicitation_responses')}`
                WHERE `session_id` = :session_id AND `request_id` = :request_id
                ORDER BY `created_at` DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId, ':request_id' => $requestId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? [
            'data' => json_decode($result['response_data'], true),
            'created_at' => $result['created_at']
        ] : null;
    }

    /**
     * Get all elicitation responses for a session
     */
    public function getElicitationResponses(string $sessionId): array
    {
        $sql = "SELECT `request_id`, `response_data`, `created_at`
                FROM `{$this->getTableName('elicitation_responses')}`
                WHERE `session_id` = :session_id
                ORDER BY `created_at` ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);
        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row['request_id'],
                'data' => json_decode($row['response_data'], true),
                'created_at' => $row['created_at']
            ];
        }
        return $responses;
    }

    /**
     * Detect database type from PDO driver for compatibility handling
     */
    private function detectDatabaseType(): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        switch ($driver) {
            case 'mysql':
                return 'mysql';
            case 'sqlite':
                return 'sqlite';
            case 'pgsql':
                return 'postgresql';
            default:
                return 'generic';
        }
    }

    /**
     * Get current timestamp in database format
     */
    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get timestamp with offset in database format
     */
    private function getTimestampWithOffset(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }

    /**
     * Database-agnostic session upsert for non-MySQL databases
     */
    private function upsertSession(string $sessionId, array $sessionData, string $expiresAt, string $createdAt): bool
    {
        // Try update first
        $updateSql = "UPDATE `{$this->getTableName('sessions')}`
                      SET `session_data` = :session_data, `expires_at` = :expires_at
                      WHERE `session_id` = :session_id";

        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute([
            ':session_id' => $sessionId,
            ':session_data' => json_encode($sessionData),
            ':expires_at' => $expiresAt
        ]);

        // If no rows updated, insert new record
        if ($updateStmt->rowCount() === 0) {
            $insertSql = "INSERT INTO `{$this->getTableName('sessions')}`
                          (`session_id`, `session_data`, `expires_at`, `created_at`)
                          VALUES (:session_id, :session_data, :expires_at, :created_at)";

            $insertStmt = $this->pdo->prepare($insertSql);
            return $insertStmt->execute([
                ':session_id' => $sessionId,
                ':session_data' => json_encode($sessionData),
                ':expires_at' => $expiresAt,
                ':created_at' => $createdAt
            ]);
        }

        return true;
    }

    /**
     * Get default configuration values
     */
    private function getDefaultConfig(): array
    {
        return [
            'table_prefix' => 'mcp_',       // Default prefix for table names
            'cleanup_interval' => 3600      // Cleanup expired sessions every hour
        ];
    }
}
