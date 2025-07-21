<?php

namespace Seolinkmap\Waasup\Storage;

/**
 * In-memory storage implementation - ONLY FOR TESTING
 *
 * WARNING: This storage loses ALL data when the process ends.
 * Never use in production - use DatabaseStorage instead.
 */
class MemoryStorage implements StorageInterface
{
    private array $messages = [];
    private array $tokens = [];
    private array $contexts = [];
    private array $sessions = [];
    private array $oauthClients = [];
    private array $authCodes = [];
    private array $users = [];
    private array $samplingResponses = [];
    private array $rootsResponses = [];
    private array $elicitationResponses = [];

    public function __construct()
    {
        if (getenv('APP_ENV') === 'production') {
            throw new \RuntimeException(
                'MemoryStorage cannot be used in production! Use DatabaseStorage instead.'
            );
        }
    }

    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool
    {
        if (!isset($this->messages[$sessionId])) {
            $this->messages[$sessionId] = [];
        }

        $this->messages[$sessionId][] = [
            'id' => uniqid(),
            'data' => $messageData,
            'context' => $context,
            'created_at' => time()
        ];

        return true;
    }

    public function getMessages(string $sessionId, array $context = []): array
    {
        return $this->messages[$sessionId] ?? [];
    }

    public function storeSamplingResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        if (!isset($this->samplingResponses[$sessionId])) {
            $this->samplingResponses[$sessionId] = [];
        }

        $this->samplingResponses[$sessionId][$requestId] = [
        'data' => $responseData,
        'created_at' => time()
        ];

        return true;
    }

    public function getSamplingResponse(string $sessionId, string $requestId): ?array
    {
        return $this->samplingResponses[$sessionId][$requestId] ?? null;
    }

    public function getSamplingResponses(string $sessionId): array
    {
        $responses = [];
        $sessionResponses = $this->samplingResponses[$sessionId] ?? [];

        foreach ($sessionResponses as $requestId => $response) {
            $responses[] = [
            'request_id' => $requestId,
            'data' => $response['data'],
            'created_at' => $response['created_at']
            ];
        }

        return $responses;
    }

    public function storeRootsResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        if (!isset($this->rootsResponses[$sessionId])) {
            $this->rootsResponses[$sessionId] = [];
        }

        $this->rootsResponses[$sessionId][$requestId] = [
        'data' => $responseData,
        'created_at' => time()
        ];

        return true;
    }

    public function getRootsResponse(string $sessionId, string $requestId): ?array
    {
        return $this->rootsResponses[$sessionId][$requestId] ?? null;
    }

    public function getRootsResponses(string $sessionId): array
    {
        $responses = [];
        $sessionResponses = $this->rootsResponses[$sessionId] ?? [];

        foreach ($sessionResponses as $requestId => $response) {
            $responses[] = [
            'request_id' => $requestId,
            'data' => $response['data'],
            'created_at' => $response['created_at']
            ];
        }

        return $responses;
    }

    public function deleteMessage(string $messageId): bool
    {
        foreach ($this->messages as $sessionId => &$messages) {
            foreach ($messages as $index => $message) {
                if ($message['id'] === $messageId) {
                    unset($messages[$index]);
                    $messages = array_values($messages);
                    return true;
                }
            }
        }
        return false;
    }

    public function validateToken(string $accessToken, array $context = []): ?array
    {
        if (isset($this->tokens[$accessToken])) {
            $token = $this->tokens[$accessToken];
            if ($token['expires_at'] > time() && !$token['revoked']) {
                return $token;
            }
        }
        return null;
    }

    public function getContextData(string $identifier, string $type = 'agency'): ?array
    {
        $key = $type . ':' . $identifier;
        return $this->contexts[$key] ?? null;
    }

    public function storeSession(string $sessionId, array $sessionData, int $ttl = 3600): bool
    {
        $this->sessions[$sessionId] = [
            'data' => $sessionData,
            'expires_at' => time() + $ttl
        ];
        return true;
    }

    public function getSession(string $sessionId): ?array
    {
        if (isset($this->sessions[$sessionId])) {
            $session = $this->sessions[$sessionId];
            if ($session['expires_at'] > time()) {
                return $session['data'];
            }
        }
        return null;
    }

    public function cleanup(): int
    {
        $cleaned = 0;
        $now = time();

        foreach ($this->messages as $sessionId => &$messages) {
            $originalCount = count($messages);
            $messages = array_filter(
                $messages,
                function ($msg) use ($now) {
                    return ($now - $msg['created_at']) < 3600;
                }
            );
            $cleaned += $originalCount - count($messages);
        }

        $originalCount = count($this->sessions);
        $this->sessions = array_filter(
            $this->sessions,
            function ($session) use ($now) {
                return $session['expires_at'] > $now;
            }
        );
        $cleaned += $originalCount - count($this->sessions);

        return $cleaned;
    }

    public function getOAuthClient(string $clientId): ?array
    {
        return $this->oauthClients[$clientId] ?? null;
    }

    public function storeOAuthClient(array $clientData): bool
    {
        $this->oauthClients[$clientData['client_id']] = $clientData;
        return true;
    }

    public function storeAuthorizationCode(string $code, array $data): bool
    {
        $this->authCodes[$code] = array_merge(
            $data,
            [
            'code' => $code,
            'created_at' => time()
            ]
        );
        return true;
    }

    public function getAuthorizationCode(string $code, string $clientId): ?array
    {
        if (isset($this->authCodes[$code])) {
            $authCode = $this->authCodes[$code];
            if ($authCode['client_id'] === $clientId && $authCode['expires_at'] > time()) {
                return $authCode;
            }
        }
        return null;
    }

    public function revokeAuthorizationCode(string $code): bool
    {
        if (isset($this->authCodes[$code])) {
            unset($this->authCodes[$code]);
            return true;
        }
        return false;
    }

    public function storeAccessToken(array $tokenData): bool
    {
        $this->tokens[$tokenData['access_token']] = $tokenData;
        return true;
    }

    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array
    {
        foreach ($this->tokens as $token) {
            if (isset($token['refresh_token'])
                && $token['refresh_token'] === $refreshToken
                && $token['client_id'] === $clientId
            ) {
                return $token;
            }
        }
        return null;
    }

    public function revokeToken(string $token): bool
    {
        if (isset($this->tokens[$token])) {
            $this->tokens[$token]['revoked'] = true;
            return true;
        }

        foreach ($this->tokens as &$tokenData) {
            if (($tokenData['refresh_token'] ?? '') === $token) {
                $tokenData['revoked'] = true;
                return true;
            }
        }
        return false;
    }

    public function getUserData(int $userId): ?array
    {
        return $this->users[$userId] ?? null;
    }

    public function verifyUserCredentials(string $email, string $password): ?array
    {
        foreach ($this->users as $user) {
            if ($user['email'] === $email && password_verify($password, $user['password'])) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }
        return null;
    }

    public function findUserByEmail(string $email): ?array
    {
        foreach ($this->users as $user) {
            if ($user['email'] === $email) {
                return [
                    'id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }
        return null;
    }

    public function findUserByGoogleId(string $googleId): ?array
    {
        foreach ($this->users as $user) {
            if (($user['google_id'] ?? null) === $googleId) {
                return [
                    'id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }
        return null;
    }

    public function findUserByLinkedinId(string $linkedinId): ?array
    {
        foreach ($this->users as $user) {
            if (($user['linkedin_id'] ?? null) === $linkedinId) {
                return [
                    'id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }
        return null;
    }

    public function findUserByGithubId(string $githubId): ?array
    {
        foreach ($this->users as $user) {
            if (($user['github_id'] ?? null) === $githubId) {
                return [
                    'id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }
        return null;
    }

    public function updateUserGoogleId(int $userId, string $googleId): bool
    {
        if (isset($this->users[$userId])) {
            $this->users[$userId]['google_id'] = $googleId;
            return true;
        }
        return false;
    }

    public function updateUserLinkedinId(int $userId, string $linkedinId): bool
    {
        if (isset($this->users[$userId])) {
            $this->users[$userId]['linkedin_id'] = $linkedinId;
            return true;
        }
        return false;
    }

    public function updateUserGithubId(int $userId, string $githubId): bool
    {
        if (isset($this->users[$userId])) {
            $this->users[$userId]['github_id'] = $githubId;
            return true;
        }
        return false;
    }

    public function addToken(string $token, array $tokenData): void
    {
        $this->tokens[$token] = $tokenData;
    }

    public function addContext(string $identifier, string $type, array $contextData): void
    {
        $key = $type . ':' . $identifier;
        $this->contexts[$key] = $contextData;
    }

    public function addUser(int $userId, array $userData): void
    {
        $this->users[$userId] = $userData;
    }

    public function addOAuthClient(string $clientId, array $clientData): void
    {
        $this->oauthClients[$clientId] = $clientData;
    }

    public function storeElicitationResponse(string $sessionId, string $requestId, array $responseData): bool
    {
        if (!isset($this->elicitationResponses[$sessionId])) {
            $this->elicitationResponses[$sessionId] = [];
        }

        $this->elicitationResponses[$sessionId][$requestId] = [
        'data' => $responseData,
        'created_at' => time()
        ];

        return true;
    }

    public function getElicitationResponse(string $sessionId, string $requestId): ?array
    {
        return $this->elicitationResponses[$sessionId][$requestId] ?? null;
    }

    public function getElicitationResponses(string $sessionId): array
    {
        $responses = [];
        $sessionResponses = $this->elicitationResponses[$sessionId] ?? [];

        foreach ($sessionResponses as $requestId => $response) {
            $responses[] = [
            'request_id' => $requestId,
            'data' => $response['data'],
            'created_at' => $response['created_at']
            ];
        }

        return $responses;
    }
}
