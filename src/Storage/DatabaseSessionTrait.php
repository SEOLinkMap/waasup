<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseSessionTrait
{
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
}
