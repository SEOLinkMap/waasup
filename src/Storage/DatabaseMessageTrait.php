<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseMessageTrait
{
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
        $sql = "INSERT INTO `{$this->getTableName('messages')}`
                (`{$this->getField('messages', 'session_id')}`, `{$this->getField('messages', 'message_data')}`, `{$this->getField('messages', 'context_data')}`, `{$this->getField('messages', 'created_at')}`)
                VALUES (:session_id, :message_data, :context_data, :created_at)";

        $params = [
            ':session_id' => $sessionId,
            ':message_data' => json_encode($messageData),
            ':context_data' => json_encode($context),
            ':created_at' => $this->getCurrentTimestamp()
        ];

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);

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
}
