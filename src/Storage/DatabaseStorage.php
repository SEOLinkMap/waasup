<?php

namespace Seolinkmap\Waasup\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Database storage implementation for MCP server data persistence
 *
 * DEFAULT USAGE: Use default table names with optional prefix
 * ================================================================
 * The simplest and recommended approach is to let this class create the default
 * table names, optionally with a prefix:
 *
 * Example (recommended):
 * ```php
 * $config = ['database' => ['table_prefix' => 'mcp_']];
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
 *     'database' => [
 *         'table_prefix' => '',  // Empty since using custom mappings
 *         'table_mapping' => [
 *             'agencies' => 'client_agency',    // Your existing agency table
 *             'users' => 'app_users',           // Your existing user table
 *             // Don't map oauth_clients - let it use default since you have no data
 *             // Don't map sessions - let it use default (these are always new)
 *         ]
 *     ]
 * ];
 * $storage = new DatabaseStorage($pdo, $config);
 * ```
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
     * @param array $config Configuration array with optional nested structure:
     *                     - 'database': Database-specific configuration
     *                       - 'table_prefix': Prefix for default table names (default: 'mcp_')
     *                       - 'table_mapping': Map logical names to existing table names (use sparingly)
     *                       - 'cleanup_interval': Cleanup frequency in seconds (default: 3600)
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(\PDO $pdo, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? $config['logger'] ?? new NullLogger();
        $this->pdo = $pdo;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
        $this->tablePrefix = $this->config['database']['table_prefix'];
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
        if (isset($this->config['database']['table_mapping'][$logicalTableName])) {
            return $this->config['database']['table_mapping'][$logicalTableName];
        }

        // Fall back to prefixed default table name
        // This is the preferred path for most use cases
        return $this->tablePrefix . $logicalTableName;
    }

    /**
 * Resolve logical field name to actual database field name
 *
 * Resolution order:
 * 1. Check field_mapping config for custom override (use only for existing tables with different field names)
 * 2. Fall back to original field name (preferred approach)
 *
 * @param string $logicalTableName One of the valid logical table names
 * @param string $logicalFieldName One of the valid logical field names for the table
 * @return string The actual database field name to use
 * @throws \InvalidArgumentException If logical table or field name is not recognized
 */
    private function getField(string $logicalTableName, string $logicalFieldName): string
    {
        // Valid logical table names that can have field mappings
        $validTableNames = [
            'agencies', 'users', 'oauth_clients', 'oauth_tokens', 'sessions',
            'messages', 'sampling_responses', 'roots_responses', 'elicitation_responses'
        ];

        // Validate the logical table name
        if (!in_array($logicalTableName, $validTableNames)) {
            throw new \InvalidArgumentException("Invalid logical table name: {$logicalTableName}. Valid names are: " . implode(', ', $validTableNames));
        }

        // Get valid fields for this table from config
        if (!isset($this->config['database']['field_mapping'][$logicalTableName])) {
            throw new \InvalidArgumentException("No field definitions found for table: {$logicalTableName}");
        }

        $validFields = array_keys($this->config['database']['field_mapping'][$logicalTableName]);
        if (!in_array($logicalFieldName, $validFields)) {
            throw new \InvalidArgumentException("Invalid logical field name '{$logicalFieldName}' for table '{$logicalTableName}'. Valid fields are: " . implode(', ', $validFields));
        }

        // Return the field mapping (either default or user override)
        return $this->config['database']['field_mapping'][$logicalTableName][$logicalFieldName];
    }

    /**
     * Store a message for SSE/streaming delivery to MCP clients
     *
     * Required fields in messages table:
     * - session_id (varchar): MCP session identifier
     * - message_data (text): JSON-encoded message data
     * - context_data (text): JSON-encoded context information
     */
    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool
    {
        $logFile = '/var/www/devsa/logs/uncaught.log';

        $sql = "INSERT INTO `{$this->getTableName('messages')}`
                (`{$this->getField('messages', 'session_id')}`, `{$this->getField('messages', 'message_data')}`, `{$this->getField('messages', 'context_data')}`, `{$this->getField('messages', 'created_at')}`)
                VALUES (:session_id, :message_data, :context_data, :created_at)";

        $params = [
            ':session_id' => $sessionId,
            ':message_data' => json_encode($messageData),
            ':context_data' => json_encode($context),
            ':created_at' => $this->getCurrentTimestamp()
        ];

        file_put_contents($logFile, "[DB-INSERT] SessionId: {$sessionId}\n", FILE_APPEND);
        file_put_contents($logFile, "[DB-INSERT] MessageData: " . $params[':message_data'] . "\n", FILE_APPEND);
        file_put_contents($logFile, "[DB-INSERT] ContextData: " . $params[':context_data'] . "\n", FILE_APPEND);
        file_put_contents($logFile, "[DB-INSERT] SQL: {$sql}\n", FILE_APPEND);

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            file_put_contents($logFile, "[DB-INSERT] SUCCESS: Message inserted successfully\n", FILE_APPEND);
        } else {
            $errorInfo = $stmt->errorInfo();
            file_put_contents($logFile, "[DB-INSERT] FAILED: " . json_encode($errorInfo) . "\n", FILE_APPEND);
        }

        return $result;
    }

    /**
     * Retrieve pending messages for a session (ordered by creation time)
     */
    public function getMessages(string $sessionId, array $context = []): array
    {
        $sql = "SELECT `{$this->getField('messages', 'id')}`, `{$this->getField('messages', 'message_data')}`, `{$this->getField('messages', 'context_data')}`, `{$this->getField('messages', 'created_at')}`
                FROM `{$this->getTableName('messages')}`
                WHERE `{$this->getField('messages', 'session_id')}` = :session_id
                ORDER BY `{$this->getField('messages', 'created_at')}` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => $row[$this->getField('messages', 'id')],
                'data' => json_decode($row[$this->getField('messages', 'message_data')], true),
                'context' => json_decode($row[$this->getField('messages', 'context_data')], true),
                'created_at' => $row[$this->getField('messages', 'created_at')]
            ];
        }
        return $messages;
    }

    /**
     * Delete a message after successful delivery
     */
    public function deleteMessage(string $messageId): bool
    {
        $sql = "DELETE FROM `{$this->getTableName('messages')}` WHERE `{$this->getField('messages', 'id')}` = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $messageId]);
    }

    /**
 * Validate OAuth bearer token with agency-level security
 *
 * Required fields in oauth_tokens table:
 * - access_token (varchar): The token to validate
 * - expires_at (datetime): Token expiration time
 * - revoked (tinyint/boolean): Whether token is revoked
 * - token_type (varchar): Must be 'Bearer' for validation
 * - agency_id (int): Must match the context agency for security
 */
    public function validateToken(string $accessToken, array $context = []): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_tokens')}`
            WHERE `{$this->getField('oauth_tokens', 'access_token')}` = :token
            AND `{$this->getField('oauth_tokens', 'expires_at')}` > :current_time
            AND `{$this->getField('oauth_tokens', 'revoked')}` = 0
            AND `{$this->getField('oauth_tokens', 'token_type')}` = 'Bearer'";

        $params = [
            ':token' => $accessToken,
            ':current_time' => $this->getCurrentTimestamp()
        ];

        // SECURITY: Verify token belongs to the requested agency/context UUID from URL
        if (!empty($context) && isset($context['context_type']) && isset($context['uuid'])) {
            if ($context['context_type'] === 'agency') {
                // Agency context - validate token's agency_id matches the UUID from URL
                $sql .= " AND `{$this->getField('oauth_tokens', 'agency_id')}` = (SELECT `{$this->getField('agencies', 'id')}` FROM `{$this->getTableName('agencies')}` WHERE `{$this->getField('agencies', 'uuid')}` = :context_uuid AND `{$this->getField('agencies', 'active')}` = 1)";
                $params[':context_uuid'] = $context['uuid'];
            } elseif ($context['context_type'] === 'user') {
                // User context - validate token's user_id matches the UUID from URL
                $sql .= " AND `{$this->getField('oauth_tokens', 'user_id')}` = (SELECT `{$this->getField('users', 'id')}` FROM `{$this->getTableName('users')}` WHERE `{$this->getField('users', 'uuid')}` = :context_uuid)";
                $params[':context_uuid'] = $context['uuid'];
            }
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }
        $normalizedResult = [];
        foreach ($this->config['database']['field_mapping']['oauth_tokens'] as $logicalField => $dbField) {
            if (isset($result[$dbField])) {
                $normalizedResult[$logicalField] = $result[$dbField];
            }
        }

        return $normalizedResult;
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
                        WHERE `{$this->getField('agencies', 'uuid')}` = :identifier AND `{$this->getField('agencies', 'active')}` = 1 LIMIT 1";
                break;
            case 'user':
                $sql = "SELECT * FROM `{$this->getTableName('users')}`
                        WHERE `{$this->getField('users', 'uuid')}` = :identifier LIMIT 1";
                break;
            default:
                return null;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            // Map database field names back to logical field names
            $normalizedResult = [];
            $tableName = $type === 'agency' ? 'agencies' : 'users';
            foreach ($this->config['database']['field_mapping'][$tableName] as $logicalField => $dbField) {
                if (isset($result[$dbField])) {
                    $normalizedResult[$logicalField] = $result[$dbField];
                }
            }
            return $normalizedResult;
        }
        return null;
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
                    (`{$this->getField('sessions', 'session_id')}`, `{$this->getField('sessions', 'session_data')}`, `{$this->getField('sessions', 'expires_at')}`, `{$this->getField('sessions', 'created_at')}`)
                    VALUES (:session_id, :session_data, :expires_at, :created_at)
                    ON DUPLICATE KEY UPDATE
                    `{$this->getField('sessions', 'session_data')}` = VALUES(`{$this->getField('sessions', 'session_data')}`),
                    `{$this->getField('sessions', 'expires_at')}` = VALUES(`{$this->getField('sessions', 'expires_at')}`)";

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
        $sql = "SELECT `{$this->getField('sessions', 'session_data')}` FROM `{$this->getTableName('sessions')}`
                WHERE `{$this->getField('sessions', 'session_id')}` = :session_id
                AND `{$this->getField('sessions', 'expires_at')}` > :current_time";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':current_time' => $this->getCurrentTimestamp()
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return json_decode($result[$this->getField('sessions', 'session_data')], true);
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
                WHERE `{$this->getField('sessions', 'expires_at')}` < :current_time";
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
        $sql = "SELECT * FROM `{$this->getTableName('oauth_clients')}` WHERE `{$this->getField('oauth_clients', 'client_id')}` = :client_id LIMIT 1";
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
                (`{$this->getField('oauth_clients', 'client_id')}`, `{$this->getField('oauth_clients', 'client_secret')}`, `{$this->getField('oauth_clients', 'client_name')}`, `{$this->getField('oauth_clients', 'redirect_uris')}`, `{$this->getField('oauth_clients', 'grant_types')}`, `{$this->getField('oauth_clients', 'response_types')}`, `{$this->getField('oauth_clients', 'created_at')}`)
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
                (`{$this->getField('oauth_tokens', 'client_id')}`, `{$this->getField('oauth_tokens', 'access_token')}`, `{$this->getField('oauth_tokens', 'token_type')}`, `{$this->getField('oauth_tokens', 'scope')}`, `{$this->getField('oauth_tokens', 'expires_at')}`, `{$this->getField('oauth_tokens', 'revoked')}`,
                 `{$this->getField('oauth_tokens', 'code_challenge')}`, `{$this->getField('oauth_tokens', 'code_challenge_method')}`, `{$this->getField('oauth_tokens', 'agency_id')}`, `{$this->getField('oauth_tokens', 'user_id')}`, `{$this->getField('oauth_tokens', 'created_at')}`)
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
                WHERE `{$this->getField('oauth_tokens', 'access_token')}` = :code
                AND `{$this->getField('oauth_tokens', 'client_id')}` = :client_id
                AND `{$this->getField('oauth_tokens', 'token_type')}` = 'authorization_code'
                AND `{$this->getField('oauth_tokens', 'expires_at')}` > :current_time
                AND `{$this->getField('oauth_tokens', 'revoked')}` = 0
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
        $sql = "UPDATE `{$this->getTableName('oauth_tokens')}` SET `{$this->getField('oauth_tokens', 'revoked')}` = 1 WHERE `{$this->getField('oauth_tokens', 'access_token')}` = :code";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':code' => $code]);
    }

    /**
     * Store OAuth access token and refresh token
     */
    public function storeAccessToken(array $tokenData): bool
    {
        $sql = "INSERT INTO `{$this->getTableName('oauth_tokens')}`
            (`{$this->getField('oauth_tokens', 'client_id')}`, `{$this->getField('oauth_tokens', 'access_token')}`, `{$this->getField('oauth_tokens', 'refresh_token')}`, `{$this->getField('oauth_tokens', 'token_type')}`, `{$this->getField('oauth_tokens', 'scope')}`, `{$this->getField('oauth_tokens', 'expires_at')}`,
             `{$this->getField('oauth_tokens', 'agency_id')}`, `{$this->getField('oauth_tokens', 'user_id')}`, `{$this->getField('oauth_tokens', 'revoked')}`, `{$this->getField('oauth_tokens', 'created_at')}`)
            VALUES (:client_id, :access_token, :refresh_token, 'Bearer', :scope,
                    :expires_at, :agency_id, :user_id, 0, :created_at)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $params = [
                ':client_id' => $tokenData['client_id'],
                ':access_token' => $tokenData['access_token'],
                ':refresh_token' => $tokenData['refresh_token'],
                ':scope' => $tokenData['scope'],
                ':expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
                ':agency_id' => $tokenData['agency_id'],
                ':user_id' => $tokenData['user_id'],
                ':created_at' => $this->getCurrentTimestamp()
            ];

            $result = $stmt->execute($params);

            if (!$result) {
                $errorInfo = $stmt->errorInfo();
            }

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get token data by refresh token (for refresh flow)
     */
    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->getTableName('oauth_tokens')}`
                WHERE `{$this->getField('oauth_tokens', 'refresh_token')}` = :refresh_token
                AND `{$this->getField('oauth_tokens', 'client_id')}` = :client_id
                AND `{$this->getField('oauth_tokens', 'revoked')}` = 0
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
                SET `{$this->getField('oauth_tokens', 'revoked')}` = 1
                WHERE (`{$this->getField('oauth_tokens', 'access_token')}` = :token OR `{$this->getField('oauth_tokens', 'refresh_token')}` = :token)";
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
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'id')} = :user_id LIMIT 1";

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
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'password')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'email')} = :email
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user[$this->getField('users', 'password')])) {
            return null;
        }

        return [
            'user_id' => $user[$this->getField('users', 'id')],
            'agency_id' => $user[$this->getField('users', 'agency_id')],
            'name' => $user[$this->getField('users', 'name')],
            'email' => $user[$this->getField('users', 'email')]
        ];
    }

    /**
     * Find user by Google OAuth ID
     * Optional field: google_id (varchar) in users table
     */
    public function findUserByGoogleId(string $googleId): ?array
    {
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'google_id')} = :google_id
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
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'linkedin_id')} = :linkedin_id
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
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'github_id')} = :github_id
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
        $sql = "SELECT u.{$this->getField('users', 'id')}, u.{$this->getField('users', 'agency_id')}, u.{$this->getField('users', 'name')}, u.{$this->getField('users', 'email')}
                FROM `{$this->getTableName('users')}` u
                WHERE u.{$this->getField('users', 'email')} = :email
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
        $sql = "UPDATE `{$this->getTableName('users')}` SET `{$this->getField('users', 'google_id')}` = :google_id WHERE `{$this->getField('users', 'id')}` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':google_id' => $googleId, ':user_id' => $userId]);
    }

    /**
     * Link user account to LinkedIn OAuth ID
     */
    public function updateUserLinkedinId(int $userId, string $linkedinId): bool
    {
        $sql = "UPDATE `{$this->getTableName('users')}` SET `{$this->getField('users', 'linkedin_id')}` = :linkedin_id WHERE `{$this->getField('users', 'id')}` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':linkedin_id' => $linkedinId, ':user_id' => $userId]);
    }

    /**
     * Link user account to GitHub OAuth ID
     */
    public function updateUserGithubId(int $userId, string $githubId): bool
    {
        $sql = "UPDATE `{$this->getTableName('users')}` SET `{$this->getField('users', 'github_id')}` = :github_id WHERE `{$this->getField('users', 'id')}` = :user_id";
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
            (`{$this->getField('sampling_responses', 'session_id')}`, `{$this->getField('sampling_responses', 'request_id')}`, `{$this->getField('sampling_responses', 'response_data')}`, `{$this->getField('sampling_responses', 'created_at')}`)
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
        $sql = "SELECT `{$this->getField('sampling_responses', 'response_data')}`, `{$this->getField('sampling_responses', 'created_at')}` FROM `{$this->getTableName('sampling_responses')}`
            WHERE `{$this->getField('sampling_responses', 'session_id')}` = :session_id AND `{$this->getField('sampling_responses', 'request_id')}` = :request_id
            ORDER BY `{$this->getField('sampling_responses', 'created_at')}` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
                'data' => json_decode($result[$this->getField('sampling_responses', 'response_data')], true),
                'created_at' => $result[$this->getField('sampling_responses', 'created_at')]
            ];
        }

        return null;
    }

    /**
     * Get all sampling responses for a session
     */
    public function getSamplingResponses(string $sessionId): array
    {
        $sql = "SELECT `{$this->getField('sampling_responses', 'request_id')}`, `{$this->getField('sampling_responses', 'response_data')}`, `{$this->getField('sampling_responses', 'created_at')}`
            FROM `{$this->getTableName('sampling_responses')}`
            WHERE `{$this->getField('sampling_responses', 'session_id')}` = :session_id
            ORDER BY `{$this->getField('sampling_responses', 'created_at')}` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row[$this->getField('sampling_responses', 'request_id')],
                'data' => json_decode($row[$this->getField('sampling_responses', 'response_data')], true),
                'created_at' => $row[$this->getField('sampling_responses', 'created_at')]
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
            (`{$this->getField('roots_responses', 'session_id')}`, `{$this->getField('roots_responses', 'request_id')}`, `{$this->getField('roots_responses', 'response_data')}`, `{$this->getField('roots_responses', 'created_at')}`)
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
        $sql = "SELECT `{$this->getField('roots_responses', 'response_data')}`, `{$this->getField('roots_responses', 'created_at')}` FROM `{$this->getTableName('roots_responses')}`
            WHERE `{$this->getField('roots_responses', 'session_id')}` = :session_id AND `{$this->getField('roots_responses', 'request_id')}` = :request_id
            ORDER BY `{$this->getField('roots_responses', 'created_at')}` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':session_id' => $sessionId,
            ':request_id' => $requestId
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
                'data' => json_decode($result[$this->getField('roots_responses', 'response_data')], true),
                'created_at' => $result[$this->getField('roots_responses', 'created_at')]
            ];
        }

        return null;
    }

    /**
     * Get all roots responses for a session
     */
    public function getRootsResponses(string $sessionId): array
    {
        $sql = "SELECT `{$this->getField('roots_responses', 'request_id')}`, `{$this->getField('roots_responses', 'response_data')}`, `{$this->getField('roots_responses', 'created_at')}`
            FROM `{$this->getTableName('roots_responses')}`
            WHERE `{$this->getField('roots_responses', 'session_id')}` = :session_id
            ORDER BY `{$this->getField('roots_responses', 'created_at')}` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);

        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row[$this->getField('roots_responses', 'request_id')],
                'data' => json_decode($row[$this->getField('roots_responses', 'response_data')], true),
                'created_at' => $row[$this->getField('roots_responses', 'created_at')]
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
                (`{$this->getField('elicitation_responses', 'session_id')}`, `{$this->getField('elicitation_responses', 'request_id')}`, `{$this->getField('elicitation_responses', 'response_data')}`, `{$this->getField('elicitation_responses', 'created_at')}`)
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
        $sql = "SELECT `{$this->getField('elicitation_responses', 'response_data')}`, `{$this->getField('elicitation_responses', 'created_at')}` FROM `{$this->getTableName('elicitation_responses')}`
                WHERE `{$this->getField('elicitation_responses', 'session_id')}` = :session_id AND `{$this->getField('elicitation_responses', 'request_id')}` = :request_id
                ORDER BY `{$this->getField('elicitation_responses', 'created_at')}` DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId, ':request_id' => $requestId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? [
            'data' => json_decode($result[$this->getField('elicitation_responses', 'response_data')], true),
            'created_at' => $result[$this->getField('elicitation_responses', 'created_at')]
        ] : null;
    }

    /**
     * Get all elicitation responses for a session
     */
    public function getElicitationResponses(string $sessionId): array
    {
        $sql = "SELECT `{$this->getField('elicitation_responses', 'request_id')}`, `{$this->getField('elicitation_responses', 'response_data')}`, `{$this->getField('elicitation_responses', 'created_at')}`
                FROM `{$this->getTableName('elicitation_responses')}`
                WHERE `{$this->getField('elicitation_responses', 'session_id')}` = :session_id
                ORDER BY `{$this->getField('elicitation_responses', 'created_at')}` ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);
        $responses = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $responses[] = [
                'request_id' => $row[$this->getField('elicitation_responses', 'request_id')],
                'data' => json_decode($row[$this->getField('elicitation_responses', 'response_data')], true),
                'created_at' => $row[$this->getField('elicitation_responses', 'created_at')]
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
                      SET `{$this->getField('sessions', 'session_data')}` = :session_data, `{$this->getField('sessions', 'expires_at')}` = :expires_at
                      WHERE `{$this->getField('sessions', 'session_id')}` = :session_id";

        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute([
            ':session_id' => $sessionId,
            ':session_data' => json_encode($sessionData),
            ':expires_at' => $expiresAt
        ]);

        // If no rows updated, insert new record
        if ($updateStmt->rowCount() === 0) {
            $insertSql = "INSERT INTO `{$this->getTableName('sessions')}`
                          (`{$this->getField('sessions', 'session_id')}`, `{$this->getField('sessions', 'session_data')}`, `{$this->getField('sessions', 'expires_at')}`, `{$this->getField('sessions', 'created_at')}`)
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
     * Get default configuration values that match the main server structure
     * Only includes database-specific configuration options used by this class
     */
    private function getDefaultConfig(): array
    {
        return [
            'database' => [
                'table_prefix' => 'mcp_',
                'cleanup_interval' => 3600,
                'table_mapping' => [],
                'field_mapping' => [
                    'agencies' => [
                        'id' => 'id',
                        'uuid' => 'uuid',
                        'name' => 'name',
                        'active' => 'active'
                    ],

                    'users' => [
                        'id' => 'id',
                        'uuid' => 'uuid',
                        'agency_id' => 'agency_id',
                        'name' => 'name',
                        'email' => 'email',
                        'password' => 'password',
                        'google_id' => 'google_id',
                        'linkedin_id' => 'linkedin_id',
                        'github_id' => 'github_id'
                    ],

                    'oauth_clients' => [
                        'client_id' => 'client_id',
                        'client_secret' => 'client_secret',
                        'client_name' => 'client_name',
                        'redirect_uris' => 'redirect_uris',
                        'grant_types' => 'grant_types',
                        'response_types' => 'response_types',
                        'created_at' => 'created_at'
                    ],

                    'oauth_tokens' => [
                        'client_id' => 'client_id',
                        'access_token' => 'access_token',
                        'refresh_token' => 'refresh_token',
                        'token_type' => 'token_type',
                        'scope' => 'scope',
                        'expires_at' => 'expires_at',
                        'agency_id' => 'agency_id',
                        'user_id' => 'user_id',
                        'resource' => 'resource',
                        'aud' => 'aud',
                        'revoked' => 'revoked',
                        'created_at' => 'created_at',
                        'code_challenge' => 'code_challenge',
                        'code_challenge_method' => 'code_challenge_method'
                    ],

                    'sessions' => [
                        'session_id' => 'session_id',
                        'session_data' => 'session_data',
                        'expires_at' => 'expires_at',
                        'created_at' => 'created_at'
                    ],

                    'messages' => [
                        'id' => 'id',
                        'session_id' => 'session_id',
                        'message_data' => 'message_data',
                        'context_data' => 'context_data',
                        'created_at' => 'created_at'
                    ],

                    'sampling_responses' => [
                        'id' => 'id',
                        'session_id' => 'session_id',
                        'request_id' => 'request_id',
                        'response_data' => 'response_data',
                        'created_at' => 'created_at'
                    ],

                    'roots_responses' => [
                        'id' => 'id',
                        'session_id' => 'session_id',
                        'request_id' => 'request_id',
                        'response_data' => 'response_data',
                        'created_at' => 'created_at'
                    ],

                    'elicitation_responses' => [
                        'id' => 'id',
                        'session_id' => 'session_id',
                        'request_id' => 'request_id',
                        'response_data' => 'response_data',
                        'created_at' => 'created_at'
                    ]
                ]
            ]
        ];
    }
}
