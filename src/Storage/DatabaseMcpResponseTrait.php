<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseMcpResponseTrait
{
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
}
