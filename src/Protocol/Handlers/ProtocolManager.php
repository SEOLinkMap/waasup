<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ProtocolManager
{
    /**
     * Methods that exist only where a session is opened with a handshake
     */
    private const HANDSHAKE_ONLY_METHODS = [
        'initialized',
        'notifications/initialized',
        'notifications/roots/list_changed',
        'roots/read',
        'roots/listDirectory'
    ];

    private StorageInterface $storage;
    private array $config;
    private ?string $statelessVersion = null;
    private ?string $statelessOwner = null;
    private array $statelessState = [];

    private const FEATURE_MATRIX = [
        '2024-11-05' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => false,
            'audio_content' => false,
            'completions' => false,
            'elicitation' => false,
            'structured_outputs' => false,
            'resource_links' => false,
            'progress_messages' => false,
            'json_rpc_batching' => false,
            'oauth_resource_server' => false,
            'resource_indicators' => false,
            'logging' => true,
            'logging_set_level' => true,
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false,
            'tasks_extension' => false,
            'tasks_any' => false,
            'stateless' => false,
            'discovery' => false,
            'cacheable_results' => false,
            'subscriptions' => false
        ],
        '2025-03-26' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => false,
            'structured_outputs' => false,
            'resource_links' => false,
            'progress_messages' => true,
            'json_rpc_batching' => true,
            'oauth_resource_server' => false,
            'resource_indicators' => false,
            'logging' => true,
            'logging_set_level' => true,
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false,
            'tasks_extension' => false,
            'tasks_any' => false,
            'stateless' => false,
            'discovery' => false,
            'cacheable_results' => false,
            'subscriptions' => false
        ],
        '2025-06-18' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => true,
            'structured_outputs' => true,
            'resource_links' => true,
            'progress_messages' => true,
            'json_rpc_batching' => false,
            'oauth_resource_server' => true,
            'resource_indicators' => true,
            'logging' => true,
            'logging_set_level' => true,
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false,
            'tasks_extension' => false,
            'tasks_any' => false,
            'stateless' => false,
            'discovery' => false,
            'cacheable_results' => false,
            'subscriptions' => false
        ],
        '2026-07-28' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => false,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => true,
            'structured_outputs' => true,
            'resource_links' => true,
            'progress_messages' => true,
            'json_rpc_batching' => false,
            'oauth_resource_server' => true,
            'resource_indicators' => true,
            'logging' => true,
            'logging_set_level' => false,
            'resource_subscriptions' => false,
            'icons' => true,
            'elicitation_url' => true,
            'sampling_tools' => true,
            'tasks' => false,
            'tasks_extension' => true,
            'tasks_any' => true,
            'stateless' => true,
            'discovery' => true,
            'cacheable_results' => true,
            'subscriptions' => true
        ],
        '2025-11-25' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => true,
            'structured_outputs' => true,
            'resource_links' => true,
            'progress_messages' => true,
            'json_rpc_batching' => false,
            'oauth_resource_server' => true,
            'resource_indicators' => true,
            'logging' => true,
            'logging_set_level' => true,
            'resource_subscriptions' => true,
            'icons' => true,
            'elicitation_url' => true,
            'sampling_tools' => true,
            'tasks' => true,
            'tasks_extension' => false,
            'tasks_any' => true,
            'stateless' => false,
            'discovery' => false,
            'cacheable_results' => false,
            'subscriptions' => false
        ]
    ];

    public function __construct(
        StorageInterface $storage,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->config = $config;
    }

    /**
     * Whether a protocol version carries its metadata per request instead of establishing a session
     */
    public static function isStatelessVersion(string $version): bool
    {
        return (self::FEATURE_MATRIX[$version]['stateless'] ?? false) === true;
    }

    /**
     * The protocol versions this library implements, newest first
     *
     * @return string[] version identifiers
     */
    public static function knownVersions(): array
    {
        $versions = array_keys(self::FEATURE_MATRIX);
        rsort($versions);

        return $versions;
    }

    /**
     * Assert every version named is one this library implements
     *
     * @param array $versions the configured versions
     * @throws ProtocolException naming the first version that has no feature set
     */
    public static function assertKnownVersions(array $versions): void
    {
        foreach ($versions as $version) {
            if (!isset(self::FEATURE_MATRIX[$version])) {
                throw new ProtocolException(
                    "Unknown protocol version '{$version}' in supported_versions. This library implements "
                    . implode(', ', self::knownVersions()) . '.',
                    -32603
                );
            }
        }
    }

    /**
     * Fingerprint of the authorization a request carries
     *
     * @param array $context the request's authorization context
     * @return string|null the fingerprint, null when the context identifies no caller
     */
    public static function contextOwner(array $context): ?string
    {
        if ($context === [] || ($context['authless'] ?? false) === true) {
            return null;
        }

        $tokenData = is_array($context['token_data'] ?? null) ? $context['token_data'] : [];

        return ($context['context_id'] ?? '') . ':' . ($tokenData['user_id'] ?? '');
    }

    /**
     * Serve this request without a stored session, at the given protocol version
     *
     * @param string|null $version the negotiated version, or null to use sessions again
     * @param string|null $owner the authorization the request carries
     */
    public function useStatelessVersion(?string $version, ?string $owner = null): void
    {
        $this->statelessVersion = $version;
        $this->statelessOwner = $owner;
        $this->statelessState = [];
    }

    /**
     * Whether this request is being served without a stored session
     */
    public function isStateless(): bool
    {
        return $this->statelessVersion !== null;
    }

    public function isMethodSupported(string $method, string $protocolVersion): bool
    {
        if ($method === 'initialize') {
            return !$this->isFeatureSupported('stateless', $protocolVersion);
        }

        if ($this->isFeatureSupported('stateless', $protocolVersion)
            && in_array($method, self::HANDSHAKE_ONLY_METHODS, true)) {
            return false;
        }

        $methodFeatureMap = [
            'ping' => 'ping',
            'tools/list' => 'tools',
            'tools/call' => 'tools',
            'prompts/list' => 'prompts',
            'prompts/get' => 'prompts',
            'resources/list' => 'resources',
            'resources/read' => 'resources',
            'resources/templates/list' => 'resources',
            'completion/complete' => 'completions',
            'completions/complete' => 'completions',
            'logging/setLevel' => 'logging_set_level',
            'server/discover' => 'discovery',
            'subscriptions/listen' => 'subscriptions',
            'resources/subscribe' => 'resource_subscriptions',
            'resources/unsubscribe' => 'resource_subscriptions',
            'tasks/get' => 'tasks_any',
            'tasks/result' => 'tasks',
            'tasks/cancel' => 'tasks_any',
            'tasks/list' => 'tasks',
            'tasks/update' => 'tasks_extension',
            'elicitation/create' => 'elicitation',
            'sampling/createMessage' => 'sampling',
            'roots/list' => 'roots',
            'roots/read' => 'roots',
            'roots/listDirectory' => 'roots',
            'notifications/initialized' => 'tools',
            'notifications/cancelled' => 'tools',
            'notifications/progress' => 'progress_notifications',
            'notifications/roots/list_changed' => 'roots'
        ];

        $feature = $methodFeatureMap[$method] ?? null;
        if (!$feature) {
            return false;
        }

        return $this->isFeatureSupported($feature, $protocolVersion);
    }

    public function isFeatureSupported(string $feature, string $protocolVersion): bool
    {
        return self::FEATURE_MATRIX[$protocolVersion][$feature] ?? false;
    }

    /**
     * Determine whether a JSON-RPC request response is returned directly on the
     * originating POST.
     */
    public function usesDirectResponse(string $protocolVersion): bool
    {
        return strcmp($protocolVersion, '2025-03-26') >= 0;
    }

    /**
     * Get protocol version from session data (authoritative source)
     */
    public function getSessionVersion(?string $sessionId): string
    {
        if ($this->statelessVersion !== null) {
            return $this->statelessVersion;
        }

        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionData = $this->storage->getSession($sessionId);

        if (!$sessionData) {
            throw new ProtocolException('Invalid or expired session ID', -32001);
        }

        if (isset($sessionData['protocol_version'])) {
            return $sessionData['protocol_version'];
        }

        if (strpos($sessionId, '_') !== false) {
            $parts = explode('_', $sessionId, 2);
            if (count($parts) === 2 && in_array($parts[0], $this->config['supported_versions'] ?? [])) {
                return $parts[0];
            }
        }

        throw new ProtocolException('No protocol version found in session', -32001);
    }

    /**
     * Build a new task record
     *
     * @param string $status the status the task has reached
     * @param int|null $requestedTtl lifetime the requestor asked for, in milliseconds
     * @return array the task, without its result
     */
    public function createTaskRecord(string $status, ?int $requestedTtl = null): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $maxTtl = (int)($this->config['tasks']['max_ttl'] ?? 3600000);
        $ttl = $requestedTtl === null
            ? (int)($this->config['tasks']['default_ttl'] ?? 300000)
            : min($requestedTtl, $maxTtl);
        $pollInterval = (int)($this->config['tasks']['poll_interval'] ?? 1000);

        $task = [
            'taskId' => bin2hex(random_bytes(16)),
            'status' => $status,
            'createdAt' => $now,
            'lastUpdatedAt' => $now
        ];

        if ($this->statelessVersion !== null) {
            $task['ttlMs'] = $ttl;
            $task['pollIntervalMs'] = $pollInterval;

            return $task;
        }

        $task['ttl'] = $ttl;
        $task['pollInterval'] = $pollInterval;

        return $task;
    }

    /**
     * Store or replace a task, dropping the oldest beyond the retention limit
     *
     * Without a session the task is stored under its own id, bound to the
     * authorization that created it.
     *
     * @param array $task the task record, keyed by taskId
     */
    public function storeTask(string $sessionId, array $task): void
    {
        if ($this->statelessVersion !== null) {
            $this->storage->storeSession(
                'task_' . $task['taskId'],
                ['owner' => $this->statelessOwner, 'task' => $task],
                (int)(($task['ttlMs'] ?? $task['ttl'] ?? 300000) / 1000)
            );

            return;
        }

        $tasks = $this->getSessionValue($sessionId, 'tasks', []);
        $tasks[$task['taskId']] = $task;

        $limit = (int)($this->config['tasks']['max_retained'] ?? 50);

        if (count($tasks) > $limit) {
            $tasks = array_slice($tasks, -$limit, null, true);
        }

        $this->storeSessionValue($sessionId, 'tasks', $tasks);
    }

    /**
     * Read one task, treating an elapsed ttl or another caller's task as gone
     *
     * @return array|null the task, or null when unknown, expired or not the caller's
     */
    public function getTask(string $sessionId, string $taskId): ?array
    {
        if ($this->statelessVersion !== null) {
            $record = $this->storage->getSession('task_' . $taskId);

            if ($record === null || ($record['owner'] ?? null) !== $this->statelessOwner) {
                return null;
            }

            $task = $record['task'] ?? [];

            return ($task === [] || $this->hasExpired($task)) ? null : $task;
        }

        $task = $this->getSessionValue($sessionId, 'tasks', [])[$taskId] ?? null;

        if ($task === null || $this->hasExpired($task)) {
            return null;
        }

        return $task;
    }

    /**
     * Read every live task on the session, oldest first
     *
     * @return array tasks indexed sequentially
     */
    public function getTasks(string $sessionId): array
    {
        $tasks = $this->getSessionValue($sessionId, 'tasks', []);

        return array_values(array_filter($tasks, fn ($task) => !$this->hasExpired($task)));
    }

    /**
     * Whether a task has outlived the ttl it was created with
     */
    private function hasExpired(array $task): bool
    {
        $ttl = $task['ttlMs'] ?? $task['ttl'] ?? null;

        if ($ttl === null) {
            return false;
        }

        return (strtotime($task['createdAt']) + (int)($ttl / 1000)) < time();
    }

    /**
     * Persist a subscription so notification senders can find its filter
     *
     * @param string $streamKey the key the notification stream polls
     * @param array $subscription the honoured filter and its id
     */
    public function storeSubscription(string $streamKey, array $subscription): void
    {
        $subscription['protocol_version'] = $this->statelessVersion ?? $this->config['supported_versions'][0];

        $this->storage->storeSession(
            $streamKey,
            $subscription,
            (int)($this->config['session_lifetime'] ?? 3600)
        );
    }

    /**
     * Server identity, trimmed to the fields a protocol version defines
     *
     * @param string $version the negotiated protocol version
     * @return array the serverInfo object, empty when none is configured
     */
    public function getServerInfo(string $version): array
    {
        $serverInfo = $this->config['server_info'] ?? [];

        if (strcmp($version, '2025-06-18') < 0) {
            unset($serverInfo['title']);
        }

        if (!$this->isFeatureSupported('icons', $version)) {
            unset($serverInfo['icons'], $serverInfo['description'], $serverInfo['websiteUrl']);
        }

        return $serverInfo;
    }

    /**
     * Freshness hint in milliseconds placed on cacheable results
     */
    public function getCacheTtlMs(): int
    {
        return (int)($this->config['cache']['ttl_ms'] ?? 60000);
    }

    /**
     * Whether shared intermediaries may cache a result: public or private
     */
    public function getCacheScope(): string
    {
        return (string)($this->config['cache']['scope'] ?? 'private');
    }

    /**
     * Items returned per page by the list methods
     */
    public function getPageSize(): int
    {
        return (int)($this->config['pagination']['page_size'] ?? 50);
    }

    /**
     * Read one value from the session record
     *
     * @param string $key session data key
     * @param mixed $default returned when the key is absent
     */
    public function getSessionValue(string $sessionId, string $key, mixed $default = null): mixed
    {
        if ($this->statelessVersion !== null) {
            return $this->statelessState[$key] ?? $default;
        }

        $sessionData = $this->storage->getSession($sessionId);

        return $sessionData[$key] ?? $default;
    }

    /**
     * Write one value into the session record
     *
     * @param string $key session data key
     */
    public function storeSessionValue(string $sessionId, string $key, mixed $value): void
    {
        $this->storeSessionValues($sessionId, [$key => $value]);
    }

    /**
     * Write several values into the session record in one pass
     *
     * @param array $values session data keyed by name
     */
    public function storeSessionValues(string $sessionId, array $values): void
    {
        if ($this->statelessVersion !== null) {
            foreach ($values as $key => $value) {
                $this->statelessState[$key] = $value;
            }

            return;
        }

        $sessionData = $this->storage->getSession($sessionId) ?? [];

        foreach ($values as $key => $value) {
            $sessionData[$key] = $value;
        }

        $sessionData['updated_at'] = time();

        $this->storage->storeSession($sessionId, $sessionData, (int)($this->config['session_lifetime'] ?? 3600));
    }

    /**
     * Store protocol version in session data
     */
    public function storeSessionVersion(string $sessionId, string $version): void
    {
        if ($this->statelessVersion !== null) {
            return;
        }

        $existingData = $this->storage->getSession($sessionId) ?? [];

        $sessionData = array_replace_recursive($existingData, [
            'protocol_version' => $version,
            'updated_at' => time()
        ]);

        $this->storage->storeSession($sessionId, $sessionData, (int)($this->config['session_lifetime'] ?? 3600));
    }
}
