<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ProtocolManager
{
    private StorageInterface $storage;
    private array $config;

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
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false
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
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false
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
            'resource_subscriptions' => true,
            'icons' => false,
            'elicitation_url' => false,
            'sampling_tools' => false,
            'tasks' => false
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
            'resource_subscriptions' => true,
            'icons' => true,
            'elicitation_url' => true,
            'sampling_tools' => true,
            'tasks' => true
        ]
    ];

    public function __construct(
        StorageInterface $storage,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->config = $config;
    }

    public function isMethodSupported(string $method, string $protocolVersion): bool
    {
        $methodFeatureMap = [
            'initialize' => 'tools',
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
            'logging/setLevel' => 'logging',
            'resources/subscribe' => 'resource_subscriptions',
            'resources/unsubscribe' => 'resource_subscriptions',
            'tasks/get' => 'tasks',
            'tasks/result' => 'tasks',
            'tasks/cancel' => 'tasks',
            'tasks/list' => 'tasks',
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

        return [
            'taskId' => bin2hex(random_bytes(16)),
            'status' => $status,
            'createdAt' => $now,
            'lastUpdatedAt' => $now,
            'ttl' => $ttl,
            'pollInterval' => (int)($this->config['tasks']['poll_interval'] ?? 1000)
        ];
    }

    /**
     * Store or replace a task on the session, dropping the oldest beyond the retention limit
     *
     * @param array $task the task record, keyed by taskId
     */
    public function storeTask(string $sessionId, array $task): void
    {
        $tasks = $this->getSessionValue($sessionId, 'tasks', []);
        $tasks[$task['taskId']] = $task;

        $limit = (int)($this->config['tasks']['max_retained'] ?? 50);

        if (count($tasks) > $limit) {
            $tasks = array_slice($tasks, -$limit, null, true);
        }

        $this->storeSessionValue($sessionId, 'tasks', $tasks);
    }

    /**
     * Read one task, treating an elapsed ttl as gone
     *
     * @return array|null the task, or null when unknown or expired
     */
    public function getTask(string $sessionId, string $taskId): ?array
    {
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
        if (($task['ttl'] ?? null) === null) {
            return false;
        }

        return (strtotime($task['createdAt']) + (int)($task['ttl'] / 1000)) < time();
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

        $existingData = $this->storage->getSession($sessionId) ?? [];

        $sessionData = array_replace_recursive($existingData, [
            'protocol_version' => $version,
            'updated_at' => time()
        ]);

        $this->storage->storeSession($sessionId, $sessionData, (int)($this->config['session_lifetime'] ?? 3600));
    }
}
