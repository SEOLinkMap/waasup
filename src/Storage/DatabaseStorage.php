<?php

namespace Seolinkmap\Waasup\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seolinkmap\Waasup\Config;

/**
 * Database storage implementation for MCP server data persistence
 *
 * @see examples/database/custom-table-configurations.md for table names, field
 *      mapping and the schema this implementation expects
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
        $this->config = Config::merge($this->getDefaultConfig(), $config);
        $this->tablePrefix = $this->config['database']['table_prefix'];
        $this->databaseType = $this->detectDatabaseType();
    }

    /**
     * Resolve logical table name to actual database table name
     *
     * @param string $logicalTableName One of the valid logical table names
     * @return string The actual database table name to use
     * @throws \InvalidArgumentException If logical table name is not recognized
     */
    private function getTableName(string $logicalTableName): string
    {

        $validTableNames = [
            'agencies',
            'users',
            'oauth_clients',
            'oauth_tokens',
            'sessions',
            'messages',
            'sampling_responses',
            'roots_responses',
            'elicitation_responses'
        ];

        if (!in_array($logicalTableName, $validTableNames)) {
            throw new \InvalidArgumentException("Invalid logical table name: {$logicalTableName}. Valid names are: " . implode(', ', $validTableNames));
        }

        if (isset($this->config['database']['table_mapping'][$logicalTableName])) {
            return $this->config['database']['table_mapping'][$logicalTableName];
        }

        return $this->tablePrefix . $logicalTableName;
    }

    /**
     * Resolve logical field name to actual database field name
     *
     * @param string $logicalTableName One of the valid logical table names
     * @param string $logicalFieldName One of the valid logical field names for the table
     * @return string The actual database field name to use
     * @throws \InvalidArgumentException If logical table or field name is not recognized
     */
    private function getField(string $logicalTableName, string $logicalFieldName): string
    {

        $validTableNames = [
            'agencies', 'users', 'oauth_clients', 'oauth_tokens', 'sessions',
            'messages', 'sampling_responses', 'roots_responses', 'elicitation_responses'
        ];

        if (!in_array($logicalTableName, $validTableNames)) {
            throw new \InvalidArgumentException("Invalid logical table name: {$logicalTableName}. Valid names are: " . implode(', ', $validTableNames));
        }

        if (!isset($this->config['database']['field_mapping'][$logicalTableName])) {
            throw new \InvalidArgumentException("No field definitions found for table: {$logicalTableName}");
        }

        $validFields = array_keys($this->config['database']['field_mapping'][$logicalTableName]);
        if (!in_array($logicalFieldName, $validFields)) {
            throw new \InvalidArgumentException("Invalid logical field name '{$logicalFieldName}' for table '{$logicalTableName}'. Valid fields are: " . implode(', ', $validFields));
        }

        $field = $this->config['database']['field_mapping'][$logicalTableName][$logicalFieldName];
        return $this->validateFieldName($field);
    }

    private function validateFieldName(string $fieldName): string
    {

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
            'base_url' => null,
            'database' => [
                'table_prefix' => 'mcp_',
                'cleanup_interval' => 3600,
                'message_lifetime' => 3600,
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
