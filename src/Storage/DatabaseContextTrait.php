<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseContextTrait
{
    /**
     * Get context data by identifier (agency or user)
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
}
