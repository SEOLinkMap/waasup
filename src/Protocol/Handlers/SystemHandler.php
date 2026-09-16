<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;

class SystemHandler
{
    public const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    public const LOG_LEVELS = [
        'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
    ];

    private ProtocolManager $protocolManager;
    private ResponseManager $responseManager;
    private array $config;

    public function __construct(
        ProtocolManager $protocolManager,
        ResponseManager $responseManager,
        array $config = []
    ) {
        $this->protocolManager = $protocolManager;
        $this->responseManager = $responseManager;
        $this->config = $config;
    }

    public function handleInitialize(array $params, mixed $id, ?string $sessionId, string $selectedVersion, Response $response): Response
    {
        if (!isset($params['protocolVersion']) || empty($params['protocolVersion'])) {
            throw new ProtocolException('Invalid params: protocolVersion required', -32602);
        }

        if ($sessionId) {
            $this->protocolManager->storeSessionVersion($sessionId, $selectedVersion);
        }

        $serverInfo = $this->protocolManager->getServerInfo($selectedVersion);

        $capabilities = $this->buildCapabilities($selectedVersion);

        if ($sessionId) {
            $this->protocolManager->storeSessionValue(
                $sessionId,
                'client_capabilities',
                $params['capabilities'] ?? []
            );
        }

        $result = [
        'protocolVersion' => $selectedVersion,
        'capabilities' => $capabilities,
        'serverInfo' => $serverInfo
        ];

        if (!empty($this->config['instructions'])) {
            $result['instructions'] = $this->config['instructions'];
        }

        $responseData = [
        'jsonrpc' => '2.0',
        'result' => $result,
        'id' => $id
        ];

        $jsonData = json_encode($responseData);

        $response->getBody()->write($jsonData);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', \Seolinkmap\Waasup\MCPSaaSServer::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Expose-Headers', \Seolinkmap\Waasup\MCPSaaSServer::EXPOSED_HEADERS)
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS')
            ->withStatus(200);
    }

    public const SUBSCRIPTION_TYPES = [
        'toolsListChanged',
        'promptsListChanged',
        'resourcesListChanged',
        'resourceSubscriptions'
    ];

    /**
     * Register the change notifications a client opted in to
     *
     * @param array $params requires the filter in 'notifications'
     * @param mixed $id the request id, which becomes the subscription id
     * @return array the honoured filter, or an error message under 'error'
     */
    public function registerSubscription(array $params, mixed $id, string $streamKey): array
    {
        $requested = $params['notifications'] ?? [];

        if (!is_array($requested) || $requested === []) {
            return ['error' => "Invalid params: 'notifications' is required and must name at least one of " . implode(', ', self::SUBSCRIPTION_TYPES) . '.'];
        }

        $unknown = array_diff(array_keys($requested), self::SUBSCRIPTION_TYPES);

        if ($unknown !== []) {
            return ['error' => 'Invalid params: unknown notification type ' . implode(', ', $unknown) . '.'];
        }

        $honoured = [];

        foreach (['toolsListChanged', 'promptsListChanged', 'resourcesListChanged'] as $type) {
            if (($requested[$type] ?? false) === true) {
                $honoured[$type] = true;
            }
        }

        $uris = $requested['resourceSubscriptions'] ?? [];

        if (is_array($uris) && $uris !== []) {
            $honoured['resourceSubscriptions'] = array_values($uris);
        }

        $this->protocolManager->storeSubscription(
            $streamKey,
            [
                'subscriptionId' => $id,
                'notifications' => $honoured,
                'subscriptions' => array_keys($honoured),
                'resource_subscriptions' => $honoured['resourceSubscriptions'] ?? []
            ]
        );

        return $honoured;
    }

    /**
     * Report supported versions, capabilities and identity without a handshake
     */
    public function handleServerDiscover(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        $version = $this->protocolManager->getSessionVersion($sessionId);

        $result = [
            'supportedVersions' => array_values($this->config['supported_versions']),
            'capabilities' => $this->buildCapabilities($version)
        ];

        if (!empty($this->config['instructions'])) {
            $result['instructions'] = $this->config['instructions'];
        }

        return $this->responseManager->storeSuccessResponse(
            $sessionId,
            $this->responseManager->cacheable($result, $sessionId),
            $id,
            $response
        );
    }

    /**
     * Capabilities this server advertises at a given protocol version
     *
     * @return array capability map
     */
    private function buildCapabilities(string $version): array
    {
        $capabilities = ['logging' => new \stdClass()];

        if ($this->protocolManager->isFeatureSupported('tools', $version)) {
            $capabilities['tools'] = ['listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('prompts', $version)) {
            $capabilities['prompts'] = ['listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('resources', $version)) {
            $capabilities['resources'] = $this->protocolManager->isFeatureSupported('resource_subscriptions', $version)
                ? ['subscribe' => true, 'listChanged' => true]
                : ['listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('completions', $version)) {
            $capabilities['completions'] = new \stdClass();
        }

        if ($this->protocolManager->isFeatureSupported('tasks', $version)) {
            $capabilities['tasks'] = [
                'list' => new \stdClass(),
                'cancel' => new \stdClass(),
                'requests' => ['tools' => ['call' => new \stdClass()]]
            ];
        }

        if ($this->protocolManager->isFeatureSupported('tasks_extension', $version)) {
            $capabilities['extensions'] = ['io.modelcontextprotocol/tasks' => new \stdClass()];
        } elseif ($this->protocolManager->isFeatureSupported('stateless', $version)) {
            $capabilities['extensions'] = new \stdClass();
        }

        return $capabilities;
    }

    public function handlePing(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        return $this->responseManager->storeSuccessResponse($sessionId, new \stdClass(), $id, $response);
    }

    /**
     * Report the current state of one task
     */
    public function handleTasksGet(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        $task = $this->requireTask($params, $sessionId);

        if ($task === null) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, $this->missingTaskMessage($params), $id, $response);
        }

        if (!$this->protocolManager->isFeatureSupported('tasks_extension', $this->protocolManager->getSessionVersion($sessionId))) {
            unset($task['result'], $task['error'], $task['inputRequests'], $task['inputResponses']);
        } else {
            unset($task['inputResponses']);
        }

        return $this->responseManager->storeSuccessResponse($sessionId, $task, $id, $response);
    }

    /**
     * Accept the input an outstanding task is waiting on
     *
     * @param array $params requires 'taskId' and 'inputResponses'
     */
    public function handleTasksUpdate(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        $task = $this->requireTask($params, $sessionId);

        if ($task === null) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, $this->missingTaskMessage($params), $id, $response);
        }

        $supplied = $params['inputResponses'] ?? [];
        $outstanding = $task['inputRequests'] ?? [];

        foreach (array_keys($outstanding) as $inputId) {
            if (isset($supplied[$inputId])) {
                unset($outstanding[$inputId]);
            }
        }

        $task['inputRequests'] = $outstanding;
        $task['inputResponses'] = array_merge($task['inputResponses'] ?? [], (array)$supplied);
        $task['status'] = $task['inputRequests'] === [] ? 'working' : 'input_required';
        $task['lastUpdatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

        $this->protocolManager->storeTask($sessionId, $task);

        return $this->responseManager->storeSuccessResponse($sessionId, new \stdClass(), $id, $response);
    }

    /**
     * Return what the request behind a terminal task produced
     */
    public function handleTasksResult(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        $task = $this->requireTask($params, $sessionId);

        if ($task === null) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, $this->missingTaskMessage($params), $id, $response);
        }

        if (!in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32603,
                "Task {$task['taskId']} is still {$task['status']}. Poll tasks/get until it reaches a terminal status.",
                $id,
                $response
            );
        }

        if (!isset($task['result'])) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Task {$task['taskId']} was {$task['status']} and holds no result.",
                $id,
                $response
            );
        }

        $result = $task['result'];
        $result['_meta']['io.modelcontextprotocol/related-task'] = ['taskId' => $task['taskId']];

        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    /**
     * Move a task that has not finished into the cancelled status
     */
    public function handleTasksCancel(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        $task = $this->requireTask($params, $sessionId);

        if ($task === null) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, $this->missingTaskMessage($params), $id, $response);
        }

        if (in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Cannot cancel task {$task['taskId']}: already in terminal status '{$task['status']}'.",
                $id,
                $response
            );
        }

        $task['status'] = 'cancelled';
        $task['statusMessage'] = 'The task was cancelled by request.';
        $task['lastUpdatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        unset($task['result']);

        $this->protocolManager->storeTask($sessionId, $task);

        return $this->responseManager->storeSuccessResponse($sessionId, $task, $id, $response);
    }

    /**
     * List the live tasks on this session
     */
    public function handleTasksList(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $tasks = array_map(
            function (array $task): array {
                unset($task['result']);
                return $task;
            },
            $this->protocolManager->getTasks($sessionId)
        );

        $page = $this->responseManager->paginate(
            $tasks,
            'taskId',
            $params['cursor'] ?? null,
            $this->protocolManager->getPageSize()
        );

        if ($page === null) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                'Invalid cursor. Re-request tasks/list without a cursor to start again.',
                $id,
                $response
            );
        }

        $result = ['tasks' => $page['items']];

        if ($page['nextCursor'] !== null) {
            $result['nextCursor'] = $page['nextCursor'];
        }

        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    /**
     * Look up the task a request names on this session
     *
     * @return array|null the task, or null when unknown or expired
     */
    private function requireTask(array $params, ?string $sessionId): ?array
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $taskId = $params['taskId'] ?? '';

        if (!is_string($taskId) || $taskId === '') {
            return null;
        }

        return $this->protocolManager->getTask($sessionId, $taskId);
    }

    /**
     * Message returned when a task cannot be found
     */
    private function missingTaskMessage(array $params): string
    {
        $taskId = is_string($params['taskId'] ?? null) ? $params['taskId'] : '';

        if ($taskId === '') {
            return "Invalid params: 'taskId' is required and must be a string.";
        }

        return "Failed to retrieve task: {$taskId} is unknown or has expired.";
    }

    /**
     * Set the minimum severity this session receives in notifications/message
     *
     * @param array $params requires an RFC 5424 severity in 'level'
     */
    public function handleLoggingSetLevel(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $level = $params['level'] ?? '';

        if (!in_array($level, self::LOG_LEVELS, true)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'level' must be one of " . implode(', ', self::LOG_LEVELS) . '.',
                $id,
                $response
            );
        }

        $this->protocolManager->storeSessionValue($sessionId, 'log_level', $level);

        return $this->responseManager->storeSuccessResponse($sessionId, new \stdClass(), $id, $response);
    }
}
