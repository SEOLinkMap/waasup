<?php

namespace Seolinkmap\Waasup\Storage;

class DatabaseStorage implements StorageInterface
{
    private \PDO $pdo;
    private string $tablePrefix;
    private array $config;
    private string $databaseType;

    public function __construct(\PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->tablePrefix = $this->config['table_prefix'];
        $this->databaseType = $this->detectDatabaseType();
    }

    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}messages`
                (`session_id`, `message_data`, `context_data`, `created_at`)
                VALUES (:session_id, :message_data, :context_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':message_data' => json_encode($messageData),
            ':context_data' => json_encode($context),
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function getMessages(string $sessionId, array $context = []): array
    {
        $sql = "SELECT `id`, `message_data`, `context_data`, `created_at`
                FROM `{$this->tablePrefix}messages`
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

    public function deleteMessage(string $messageId): bool
    {
        $sql = "DELETE FROM `{$this->tablePrefix}messages` WHERE `id` = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $messageId]);
    }

    public function validateToken(string $accessToken, array $context = []): ?array
    {
        $sql = "SELECT * FROM `{$this->tablePrefix}oauth_tokens`
                WHERE `access_token` = :token
                AND `expires_at` > :current_time
                AND `revoked` = 0
                AND `token_type` = 'Bearer'
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':token' => $accessToken,
            ':current_time' => $this->getCurrentTimestamp()
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getContextData(string $identifier, string $type = 'agency'): ?array
    {
        switch ($type) {
        case 'agency':
            $sql = "SELECT * FROM `{$this->tablePrefix}agencies`
                        WHERE `uuid` = :identifier AND `active` = 1 LIMIT 1";
            break;
        case 'user':
            $sql = "SELECT * FROM `{$this->tablePrefix}users`
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

    public function storeSession(string $sessionId, array $sessionData, int $ttl = 3600): bool
    {
        $expiresAt = $this->getTimestampWithOffset($ttl);
        $createdAt = $this->getCurrentTimestamp();

        if ($this->databaseType === 'mysql') {
            $sql = "INSERT INTO `{$this->tablePrefix}sessions`
                    (`session_id`, `session_data`, `expires_at`, `created_at`)
                    VALUES (:session_id, :session_data, :expires_at, :created_at)
                    ON DUPLICATE KEY UPDATE
                    `session_data` = VALUES(`session_data`),
                    `expires_at` = VALUES(`expires_at`)";

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(
                [
                ':session_id' => $sessionId,
                ':session_data' => json_encode($sessionData),
                ':expires_at' => $expiresAt,
                ':created_at' => $createdAt
                ]
            );
        } else {
            return $this->upsertSession($sessionId, $sessionData, $expiresAt, $createdAt);
        }
    }

    public function getSession(string $sessionId): ?array
    {
        $sql = "SELECT `session_data` FROM `{$this->tablePrefix}sessions`
                WHERE `session_id` = :session_id
                AND `expires_at` > :current_time";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':current_time' => $this->getCurrentTimestamp()
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return json_decode($result['session_data'], true);
        }

        return null;
    }

    public function cleanup(): int
    {
        $cleaned = 0;
        $currentTime = $this->getCurrentTimestamp();
        $oneHourAgo = $this->getTimestampWithOffset(-3600);

        $sql = "DELETE FROM `{$this->tablePrefix}messages`
                WHERE `created_at` < :one_hour_ago";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':one_hour_ago' => $oneHourAgo]);
        $cleaned += $stmt->rowCount();

        $sql = "DELETE FROM `{$this->tablePrefix}sessions`
                WHERE `expires_at` < :current_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':current_time' => $currentTime]);
        $cleaned += $stmt->rowCount();

        return $cleaned;
    }

    public function getOAuthClient(string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->tablePrefix}oauth_clients` WHERE `client_id` = :client_id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':client_id' => $clientId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function storeOAuthClient(array $clientData): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}oauth_clients`
                (`client_id`, `client_secret`, `client_name`, `redirect_uris`, `grant_types`, `response_types`, `created_at`)
                VALUES (:client_id, :client_secret, :client_name, :redirect_uris, :grant_types, :response_types, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':client_id' => $clientData['client_id'],
            ':client_secret' => $clientData['client_secret'],
            ':client_name' => $clientData['client_name'],
            ':redirect_uris' => json_encode($clientData['redirect_uris']),
            ':grant_types' => json_encode($clientData['grant_types']),
            ':response_types' => json_encode($clientData['response_types']),
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function storeAuthorizationCode(string $code, array $data): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}oauth_tokens`
                (`client_id`, `access_token`, `token_type`, `scope`, `expires_at`, `revoked`,
                 `code_challenge`, `code_challenge_method`, `agency_id`, `user_id`, `created_at`)
                VALUES (:client_id, :auth_code, 'authorization_code', :scope,
                        :expires_at, 0, :code_challenge, :code_challenge_method,
                        :agency_id, :user_id, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':client_id' => $data['client_id'],
            ':auth_code' => $code,
            ':scope' => $data['scope'],
            ':expires_at' => $this->getTimestampWithOffset($data['expires_at'] - time()),
            ':code_challenge' => $data['code_challenge'],
            ':code_challenge_method' => $data['code_challenge_method'],
            ':agency_id' => $data['agency_id'],
            ':user_id' => $data['user_id'],
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function getAuthorizationCode(string $code, string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->tablePrefix}oauth_tokens`
                WHERE `access_token` = :code
                AND `client_id` = :client_id
                AND `token_type` = 'authorization_code'
                AND `expires_at` > :current_time
                AND `revoked` = 0
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':code' => $code,
            ':client_id' => $clientId,
            ':current_time' => $this->getCurrentTimestamp()
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function revokeAuthorizationCode(string $code): bool
    {
        $sql = "UPDATE `{$this->tablePrefix}oauth_tokens` SET `revoked` = 1 WHERE `access_token` = :code";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':code' => $code]);
    }

    public function storeAccessToken(array $tokenData): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}oauth_tokens`
                (`client_id`, `access_token`, `refresh_token`, `token_type`, `scope`, `expires_at`,
                 `agency_id`, `user_id`, `revoked`, `created_at`)
                VALUES (:client_id, :access_token, :refresh_token, 'Bearer', :scope,
                        :expires_at, :agency_id, :user_id, 0, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':client_id' => $tokenData['client_id'],
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'],
            ':scope' => $tokenData['scope'],
            ':expires_at' => $this->getTimestampWithOffset($tokenData['expires_at'] - time()),
            ':agency_id' => $tokenData['agency_id'],
            ':user_id' => $tokenData['user_id'],
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array
    {
        $sql = "SELECT * FROM `{$this->tablePrefix}oauth_tokens`
                WHERE `refresh_token` = :refresh_token
                AND `client_id` = :client_id
                AND `revoked` = 0
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':refresh_token' => $refreshToken,
            ':client_id' => $clientId
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function revokeToken(string $token): bool
    {
        $sql = "UPDATE `{$this->tablePrefix}oauth_tokens`
                SET `revoked` = 1
                WHERE (`access_token` = :token OR `refresh_token` = :token)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':token' => $token]);
    }

    public function getUserData(int $userId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
                WHERE u.id = :user_id LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function verifyUserCredentials(string $email, string $password): ?array
    {
        $sql = "SELECT u.id, u.password, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
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

    public function findUserByGoogleId(string $googleId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
                WHERE u.google_id = :google_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':google_id' => $googleId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findUserByLinkedinId(string $linkedinId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
                WHERE u.linkedin_id = :linkedin_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':linkedin_id' => $linkedinId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findUserByGithubId(string $githubId): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
                WHERE u.github_id = :github_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':github_id' => $githubId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findUserByEmail(string $email): ?array
    {
        $sql = "SELECT u.id, u.agency_id, u.name, u.email
                FROM `{$this->tablePrefix}users` u
                WHERE u.email = :email
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateUserGoogleId(int $userId, string $googleId): bool
    {
        $sql = "UPDATE `{$this->tablePrefix}users` SET `google_id` = :google_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':google_id' => $googleId, ':user_id' => $userId]);
    }

    public function updateUserLinkedinId(int $userId, string $linkedinId): bool
    {
        $sql = "UPDATE `{$this->tablePrefix}users` SET `linkedin_id` = :linkedin_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':linkedin_id' => $linkedinId, ':user_id' => $userId]);
    }

    public function updateUserGithubId(int $userId, string $githubId): bool
    {
        $sql = "UPDATE `{$this->tablePrefix}users` SET `github_id` = :github_id WHERE `id` = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':github_id' => $githubId, ':user_id' => $userId]);
    }

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

    private function getCurrentTimestamp(): string
    {
        if ($this->databaseType === 'sqlite') {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s');
    }

    private function getTimestampWithOffset(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }

    private function upsertSession(string $sessionId, array $sessionData, string $expiresAt, string $createdAt): bool
    {
        $updateSql = "UPDATE `{$this->tablePrefix}sessions`
                      SET `session_data` = :session_data, `expires_at` = :expires_at
                      WHERE `session_id` = :session_id";

        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute(
            [
            ':session_id' => $sessionId,
            ':session_data' => json_encode($sessionData),
            ':expires_at' => $expiresAt
            ]
        );

        if ($updateStmt->rowCount() === 0) {
            $insertSql = "INSERT INTO `{$this->tablePrefix}sessions`
                          (`session_id`, `session_data`, `expires_at`, `created_at`)
                          VALUES (:session_id, :session_data, :expires_at, :created_at)";

            $insertStmt = $this->pdo->prepare($insertSql);
            return $insertStmt->execute(
                [
                ':session_id' => $sessionId,
                ':session_data' => json_encode($sessionData),
                ':expires_at' => $expiresAt,
                ':created_at' => $createdAt
                ]
            );
        }

        return true;
    }

    public function storeSamplingResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}sampling_responses`
            (`session_id`, `request_id`, `response_data`, `created_at`)
            VALUES (:session_id, :request_id, :response_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':request_id' => $requestId,
            ':response_data' => json_encode($responseData),
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function getSamplingResponse(string $sessionId, string $requestId): ?array
    {
        $sql = "SELECT `response_data`, `created_at` FROM `{$this->tablePrefix}sampling_responses`
            WHERE `session_id` = :session_id AND `request_id` = :request_id
            ORDER BY `created_at` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':request_id' => $requestId
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
            'data' => json_decode($result['response_data'], true),
            'created_at' => $result['created_at']
            ];
        }

        return null;
    }

    public function getSamplingResponses(string $sessionId): array
    {
        $sql = "SELECT `request_id`, `response_data`, `created_at`
            FROM `{$this->tablePrefix}sampling_responses`
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

    public function storeRootsResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        $sql = "INSERT INTO `{$this->tablePrefix}roots_responses`
            (`session_id`, `request_id`, `response_data`, `created_at`)
            VALUES (:session_id, :request_id, :response_data, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':request_id' => $requestId,
            ':response_data' => json_encode($responseData),
            ':created_at' => $this->getCurrentTimestamp()
            ]
        );
    }

    public function getRootsResponse(string $sessionId, string $requestId): ?array
    {
        $sql = "SELECT `response_data`, `created_at` FROM `{$this->tablePrefix}roots_responses`
            WHERE `session_id` = :session_id AND `request_id` = :request_id
            ORDER BY `created_at` DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(
            [
            ':session_id' => $sessionId,
            ':request_id' => $requestId
            ]
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            return [
            'data' => json_decode($result['response_data'], true),
            'created_at' => $result['created_at']
            ];
        }

        return null;
    }

    public function getRootsResponses(string $sessionId): array
    {
        $sql = "SELECT `request_id`, `response_data`, `created_at`
            FROM `{$this->tablePrefix}roots_responses`
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

    private function getDefaultConfig(): array
    {
        return [
            'table_prefix' => 'mcp_',
            'cleanup_interval' => 3600
        ];
    }
}
