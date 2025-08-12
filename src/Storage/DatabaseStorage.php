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
    use DatabaseOAuthTrait;
    use DatabaseUserTrait;
    use DatabaseSessionTrait;
    use DatabaseMessageTrait;
    use DatabaseMcpResponseTrait;
    use DatabaseContextTrait;

    private \PDO $pdo;
    private LoggerInterface $logger;
    private string $tablePrefix;
    private array $config;
    private string $databaseType;

    /**
     * Initialize database storage
     *
     * @param \PDO $pdo Database connection
     * @param array $config config array (master in MCPSaaSServer::getDefaultConfig())
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(\PDO $pdo, array $config = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
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
        $field = $this->config['database']['field_mapping'][$logicalTableName][$logicalFieldName];
        return $this->validateFieldName($field);
    }

    private function validateFieldName(string $fieldName): string
    {
        // Only allow alphanumeric, underscores, and common database chars
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            throw new \InvalidArgumentException("Invalid field name: {$fieldName}");
        }
        return $fieldName;
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
