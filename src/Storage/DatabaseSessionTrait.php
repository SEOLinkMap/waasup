<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseSessionTrait
{
    /**
     * Store MCP session data with TTL
     */
    public function storeSession(string $sessionId, array $sessionData, int $ttl = 3600): bool
    {
        $expiresAt = $this->getTimestampWithOffset($ttl);
        $createdAt = $this->getCurrentTimestamp();

        if (random_int(0, 99) < 1) {
            $this->cleanup();
        }

        if ($this->databaseType === 'mysql') {

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
     * Database-agnostic session upsert for non-MySQL databases
     */
    private function upsertSession(string $sessionId, array $sessionData, string $expiresAt, string $createdAt): bool
    {

        $updateSql = "UPDATE `{$this->getTableName('sessions')}`
                      SET `{$this->getField('sessions', 'session_data')}` = :session_data, `{$this->getField('sessions', 'expires_at')}` = :expires_at
                      WHERE `{$this->getField('sessions', 'session_id')}` = :session_id";

        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute([
            ':session_id' => $sessionId,
            ':session_data' => json_encode($sessionData),
            ':expires_at' => $expiresAt
        ]);

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
     * Clean up expired sessions and undelivered messages
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

        $sql = "DELETE FROM `{$this->getTableName('messages')}`
                WHERE `{$this->getField('messages', 'created_at')}` < :expiry";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':expiry' => $this->getTimestampWithOffset(-(int)$this->config['database']['message_lifetime'])]);
        $cleaned += $stmt->rowCount();

        return $cleaned;
    }
}
