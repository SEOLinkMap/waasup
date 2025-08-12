<?php

namespace Seolinkmap\Waasup\Storage;

trait DatabaseUserTrait
{
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
}
