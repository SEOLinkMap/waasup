<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseOAuthTrait
{
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

        if (!empty($context) && isset($context['context_type']) && isset($context['uuid'])) {
            if ($context['context_type'] === 'agency') {
                $sql .= " AND `{$this->getField('oauth_tokens', 'agency_id')}` = (SELECT `{$this->getField('agencies', 'id')}` FROM `{$this->getTableName('agencies')}` WHERE `{$this->getField('agencies', 'uuid')}` = :context_uuid AND `{$this->getField('agencies', 'active')}` = 1)";
                $params[':context_uuid'] = $context['uuid'];
            } elseif ($context['context_type'] === 'user') {
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

        // Convert aud field from JSON string back to array
        if (isset($normalizedResult['aud']) && $normalizedResult['aud'] !== null) {
            $decodedAud = json_decode($normalizedResult['aud'], true);
            if ($decodedAud !== null) {
                $normalizedResult['aud'] = $decodedAud;
            }
        }

        return $normalizedResult;
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
                 `{$this->getField('oauth_tokens', 'code_challenge')}`, `{$this->getField('oauth_tokens', 'code_challenge_method')}`, `{$this->getField('oauth_tokens', 'agency_id')}`, `{$this->getField('oauth_tokens', 'user_id')}`, `{$this->getField('oauth_tokens', 'resource')}`, `{$this->getField('oauth_tokens', 'created_at')}`)
                VALUES (:client_id, :auth_code, 'authorization_code', :scope,
                        :expires_at, 0, :code_challenge, :code_challenge_method,
                        :agency_id, :user_id, :resource, :created_at)";

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
            ':resource' => $this->config['base_url'],
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

        if (!$result) {
            return null;
        }

        // Map database field names back to logical field names
        $normalizedResult = [];
        foreach ($this->config['database']['field_mapping']['oauth_tokens'] as $logicalField => $dbField) {
            if (isset($result[$dbField])) {
                $normalizedResult[$logicalField] = $result[$dbField];
            }
        }

        return $normalizedResult;
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
         `{$this->getField('oauth_tokens', 'agency_id')}`, `{$this->getField('oauth_tokens', 'user_id')}`, `{$this->getField('oauth_tokens', 'revoked')}`, `{$this->getField('oauth_tokens', 'resource')}`, `{$this->getField('oauth_tokens', 'aud')}`, `{$this->getField('oauth_tokens', 'created_at')}`)
        VALUES (:client_id, :access_token, :refresh_token, 'Bearer', :scope,
                :expires_at, :agency_id, :user_id, 0, :resource, :aud, :created_at)";

        $baseUrl = $this->config['base_url'];
        if ($baseUrl === null || $baseUrl === '') {
            $resourceValue = null;
            $audValue = null;
        } else {
            $resourceValue = $baseUrl;

            if (isset($tokenData['aud']) && $tokenData['aud'] !== null) {
                if (is_array($tokenData['aud'])) {
                    $audValue = json_encode($tokenData['aud']);
                } else {
                    $audValue = json_encode([$tokenData['aud']]);
                }
            } else {
                $audValue = json_encode([$baseUrl]);
            }
        }

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
                ':resource' => $resourceValue,
                ':aud' => $audValue,
                ':created_at' => $this->getCurrentTimestamp()
            ];

            $result = $stmt->execute($params);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to store access token', [
                'client_id' => $tokenData['client_id'],
                'error' => $e->getMessage()
            ]);
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
}
