<?php

namespace Seolinkmap\Waasup\Protocol;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Protocol\Handlers\ContentProcessor;
use Seolinkmap\Waasup\Protocol\Handlers\PromptsHandler;
use Seolinkmap\Waasup\Protocol\Handlers\ProtocolManager;
use Seolinkmap\Waasup\Protocol\Handlers\ResourcesHandler;
use Seolinkmap\Waasup\Protocol\Handlers\ResponseManager;
use Seolinkmap\Waasup\Protocol\Handlers\SamplingHandler;
use Seolinkmap\Waasup\Protocol\Handlers\SystemHandler;
use Seolinkmap\Waasup\Protocol\Handlers\ToolsHandler;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class MessageHandler
{
    public const SEEN_REQUEST_ID_LIMIT = 500;

    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private StorageInterface $storage;
    private array $config;
    private array $sessionVersionCache = [];
    private ProtocolManager $protocolManager;
    private ToolsHandler $toolsHandler;
    private PromptsHandler $promptsHandler;
    private ResourcesHandler $resourcesHandler;
    private SamplingHandler $samplingHandler;
    private SystemHandler $systemHandler;
    private ContentProcessor $contentProcessor;
    private ResponseManager $responseManager;

    public function __construct(
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        StorageInterface $storage,
        array $config = []
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->resourceRegistry = $resourceRegistry;
        $this->storage = $storage;
        $this->config = $config;

        $this->protocolManager = new ProtocolManager($storage, $config);
        $this->contentProcessor = new ContentProcessor($this->protocolManager);
        $this->responseManager = new ResponseManager($storage, $this->protocolManager);

        $this->toolsHandler = new ToolsHandler(
            $this->toolRegistry,
            $this->promptRegistry,
            $this->protocolManager,
            $this->contentProcessor,
            $this->responseManager,
            $this->resourceRegistry
        );

        $this->promptsHandler = new PromptsHandler(
            $this->promptRegistry,
            $this->responseManager,
            $this->protocolManager
        );

        $this->resourcesHandler = new ResourcesHandler(
            $this->resourceRegistry,
            $this->responseManager,
            $this->protocolManager
        );

        $this->samplingHandler = new SamplingHandler(
            $this->storage,
            $this->responseManager,
            $this->protocolManager
        );

        $this->systemHandler = new SystemHandler(
            $this->protocolManager,
            $this->responseManager,
            $this->config
        );
    }

    public function processMessage(
        array $data,
        ?string $sessionId,
        array $context,
        Response $response
    ): Response {
        if (!isset($this->sessionVersionCache[$sessionId])) {
            $this->sessionVersionCache[$sessionId] =
                $this->protocolManager->getSessionVersion($sessionId);
        }
        $protocolVersion = $this->sessionVersionCache[$sessionId];

        if (strcmp($protocolVersion, '2025-06-18') >= 0 && !($context['authless'] ?? false)) {
            $headerVersion = $context['protocol_version'] ?? '';

            if ($headerVersion !== '' && $headerVersion !== $protocolVersion) {
                throw new ProtocolException(
                    "MCP-Protocol-Version header says {$headerVersion} but this session negotiated {$protocolVersion}. Send MCP-Protocol-Version: {$protocolVersion} or start a new session with initialize.",
                    -32600
                );
            }
        }

        if ($this->isBatchRequest($data)) {
            if (!$this->protocolManager->isFeatureSupported('json_rpc_batching', $protocolVersion)) {
                throw new ProtocolException('JSON-RPC batching not supported in this protocol version', -32600);
            }
            return $this->processBatchRequest($data, $sessionId, $context, $response, $protocolVersion);
        }

        return $this->processSingleMessage($data, $sessionId, $context, $response, $protocolVersion);
    }

    public function handleInitialize(array $params, mixed $id, ?string $sessionId, string $selectedVersion, Response $response): Response
    {
        return $this->systemHandler->handleInitialize($params, $id, $sessionId, $selectedVersion, $response);
    }

    public function requestSampling(
        string $sessionId,
        array $messages,
        array $options = [],
        array $context = []
    ): string {
        return $this->samplingHandler->requestSampling($sessionId, $messages, $options, $context);
    }

    public function requestRootsList(string $sessionId, array $context = []): string
    {
        return $this->samplingHandler->requestRootsList($sessionId, $context);
    }

    public function requestRootsRead(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        return $this->samplingHandler->requestRootsRead($sessionId, $uri, $options, $context);
    }

    public function requestRootsListDirectory(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        return $this->samplingHandler->requestRootsListDirectory($sessionId, $uri, $options, $context);
    }

    /**
     * Send a progress notification for the request being handled
     *
     * @param int|float $progress work done so far
     * @param string $message human readable step description, 2025-03-26 and later
     * @param int|float|null $total expected total, omitted when unknown
     * @param mixed $progressToken overrides the token of the current request
     */
    public function sendProgressNotification(
        string $sessionId,
        int|float $progress,
        string $message = '',
        int|float|null $total = null,
        mixed $progressToken = null
    ): void {
        if (!isset($this->sessionVersionCache[$sessionId])) {
            $this->sessionVersionCache[$sessionId] =
                $this->protocolManager->getSessionVersion($sessionId);
        }
        $protocolVersion = $this->sessionVersionCache[$sessionId];

        if (!$this->protocolManager->isFeatureSupported('progress_notifications', $protocolVersion)) {
            return;
        }

        $progressToken = $progressToken ?? $this->protocolManager->getSessionValue($sessionId, 'progress_token');

        if ($progressToken === null) {
            return;
        }

        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => [
                'progressToken' => $progressToken,
                'progress' => $progress
            ]
        ];

        if ($total !== null) {
            $notification['params']['total'] = $total;
        }

        if ($message !== '' && $this->protocolManager->isFeatureSupported('progress_messages', $protocolVersion)) {
            $notification['params']['message'] = $message;
        }

        $this->storage->storeMessage($sessionId, $notification);
    }

    /**
     * Send a list_changed notification
     *
     * @param string $listType one of tools, prompts or resources
     */
    public function sendListChangedNotification(string $sessionId, string $listType): void
    {
        if (!in_array($listType, ['tools', 'prompts', 'resources'], true)) {
            throw new ProtocolException("List type must be tools, prompts or resources, {$listType} given.", -32602);
        }

        $this->storage->storeMessage(
            $sessionId,
            [
                'jsonrpc' => '2.0',
                'method' => "notifications/{$listType}/list_changed"
            ]
        );
    }

    /**
     * Send a resources/updated notification to a subscribed session
     */
    public function sendResourceUpdatedNotification(string $sessionId, string $uri): void
    {
        $subscriptions = $this->protocolManager->getSessionValue($sessionId, 'resource_subscriptions', []);

        if (!in_array($uri, $subscriptions, true)) {
            return;
        }

        $this->storage->storeMessage(
            $sessionId,
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/resources/updated',
                'params' => ['uri' => $uri]
            ]
        );
    }

    /**
     * Send a notifications/message log record
     *
     * @param string $level RFC 5424 severity
     * @param mixed $data the log payload
     * @param string $logger optional logger name
     */
    public function sendLogMessage(string $sessionId, string $level, mixed $data, string $logger = ''): void
    {
        $levels = SystemHandler::LOG_LEVELS;
        $minimum = $this->protocolManager->getSessionValue($sessionId, 'log_level');

        if (!in_array($level, $levels, true)) {
            throw new ProtocolException("Log level must be one of " . implode(', ', $levels) . ", {$level} given.", -32602);
        }

        if ($minimum === null || array_search($level, $levels, true) < array_search($minimum, $levels, true)) {
            return;
        }

        $params = ['level' => $level, 'data' => $data];

        if ($logger !== '') {
            $params['logger'] = $logger;
        }

        $this->storage->storeMessage(
            $sessionId,
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/message',
                'params' => $params
            ]
        );
    }

    public function requestElicitation(
        string $sessionId,
        string $message,
        ?array $requestedSchema = null,
        array $context = [],
        array $options = []
    ): string {
        return $this->samplingHandler->requestElicitation($sessionId, $message, $requestedSchema, $context, $options);
    }

    private function isBatchRequest(array $data): bool
    {
        return array_keys($data) === range(0, count($data) - 1);
    }

    private function processBatchRequest(
        array $batchData,
        ?string $sessionId,
        array $context,
        Response $response,
        string $protocolVersion
    ): Response {
        if (empty($batchData)) {
            throw new ProtocolException('Invalid Request: empty batch', -32600);
        }

        $batchResponses = [];
        $hasNotifications = false;
        $body = $response->getBody();
        $consumed = 0;

        foreach ($batchData as $requestData) {
            if (!is_array($requestData)) {
                throw new ProtocolException('Invalid Request: batch item must be object', -32600);
            }

            $hasId = array_key_exists('id', $requestData);
            $isNotification = !$hasId || $requestData['id'] === null;

            try {
                $this->processSingleMessage($requestData, $sessionId, $context, $response, $protocolVersion);

                if ($isNotification) {
                    $hasNotifications = true;
                    continue;
                }

                $written = (string) $body;
                $responseData = json_decode(substr($written, $consumed));
                $consumed = strlen($written);

                if ($responseData instanceof \stdClass
                    && (property_exists($responseData, 'result') || property_exists($responseData, 'error'))) {
                    $batchResponses[] = $responseData;
                }
            } catch (ProtocolException $e) {
                $batchResponses[] = [
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ],
                    'id' => $requestData['id'] ?? null
                ];
            }
        }

        if (empty($batchResponses) && $hasNotifications) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withStatus(202);
        }

        $encoded = json_encode($batchResponses);
        $payload = $encoded === false ? '[]' : $encoded;
        $written = strlen((string) $body);
        if (strlen($payload) < $written) {
            $payload .= str_repeat(' ', $written - strlen($payload));
        }
        $body->rewind();
        $body->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withStatus(200);
    }

    private function processSingleMessage(
        array $data,
        ?string $sessionId,
        array $context,
        Response $response,
        string $protocolVersion
    ): Response {

        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new ProtocolException("Invalid Request: every message must carry \"jsonrpc\": \"2.0\".", -32600);
        }

        if (!isset($data['method']) && array_key_exists('id', $data)
            && (array_key_exists('result', $data) || array_key_exists('error', $data))) {
            if ($sessionId) {
                $this->samplingHandler->storeClientResponse(
                    $sessionId,
                    $data['id'],
                    is_array($data['result'] ?? null) ? $data['result'] : ['error' => $data['error'] ?? null]
                );
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withStatus(202);
        }

        if (!isset($data['method'])) {
            throw new ProtocolException("Invalid Request: a request needs a \"method\", a response needs \"result\" or \"error\" alongside its \"id\".", -32600);
        }

        $method = $data['method'];
        $params = $data['params'] ?? [];

        $hasId = array_key_exists('id', $data);
        $id = $hasId ? $data['id'] : null;

        $isExplicitNotification = str_starts_with($method, 'notifications/') || in_array($method, ['initialized']);

        if (!$isExplicitNotification && (!$hasId || $data['id'] === null)) {
            throw new ProtocolException("Request id cannot be null: give \"{$method}\" a unique string or number id, or send it as a notification under the notifications/ prefix.", -32600);
        }

        $isNotification = $isExplicitNotification || !$hasId;

        if ($method !== 'initialize') {
            if (!$this->protocolManager->isMethodSupported($method, $protocolVersion)) {
                if ($isNotification) {
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withHeader('Access-Control-Allow-Origin', '*')
                        ->withStatus(202);
                } else {
                    return $this->responseManager->storeErrorResponse($sessionId, -32601, "Method not supported in protocol version {$protocolVersion}", $id, $response);
                }
            }
        }

        if (!$isNotification && $sessionId !== null) {
            $seenIds = $this->protocolManager->getSessionValue($sessionId, 'seen_request_ids', []);

            if ($id !== null && in_array($id, $seenIds, true)) {
                throw new ProtocolException("Duplicate request id {$id}: this id was already used on this session. Use a fresh id for each request.", -32600);
            }

            if ($id !== null) {
                $seenIds[] = $id;
                $seenIds = array_slice($seenIds, -self::SEEN_REQUEST_ID_LIMIT);
            }

            $this->protocolManager->storeSessionValues(
                $sessionId,
                [
                    'progress_token' => $params['_meta']['progressToken'] ?? null,
                    'seen_request_ids' => $seenIds
                ]
            );
        }

        if ($isNotification) {
            $this->processNotification($method, $params, $sessionId, $protocolVersion);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withStatus(202);
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->systemHandler->handleInitialize($params, $id, $sessionId, $protocolVersion, $response);

                case 'ping':
                    return $this->systemHandler->handlePing($id, $sessionId, $context, $response);

                case 'tools/list':
                    return $this->toolsHandler->handleToolsList($params, $id, $sessionId, $context, $response);

                case 'tools/call':
                    return $this->toolsHandler->handleToolsCall($params, $id, $sessionId, $context, $response);

                case 'prompts/list':
                    return $this->promptsHandler->handlePromptsList($params, $id, $sessionId, $context, $response);

                case 'prompts/get':
                    return $this->promptsHandler->handlePromptsGet($params, $id, $sessionId, $context, $response);

                case 'resources/list':
                    return $this->resourcesHandler->handleResourcesList($params, $id, $sessionId, $context, $response);

                case 'resources/read':
                    return $this->resourcesHandler->handleResourcesRead($params, $id, $sessionId, $context, $response);

                case 'resources/templates/list':
                    return $this->resourcesHandler->handleResourceTemplatesList($params, $id, $sessionId, $context, $response);

                case 'resources/subscribe':
                    return $this->resourcesHandler->handleResourcesSubscribe($params, $id, $sessionId, $context, $response);

                case 'resources/unsubscribe':
                    return $this->resourcesHandler->handleResourcesUnsubscribe($params, $id, $sessionId, $context, $response);

                case 'tasks/get':
                    return $this->systemHandler->handleTasksGet($params, $id, $sessionId, $context, $response);

                case 'tasks/result':
                    return $this->systemHandler->handleTasksResult($params, $id, $sessionId, $context, $response);

                case 'tasks/cancel':
                    return $this->systemHandler->handleTasksCancel($params, $id, $sessionId, $context, $response);

                case 'tasks/list':
                    return $this->systemHandler->handleTasksList($params, $id, $sessionId, $context, $response);

                case 'logging/setLevel':
                    return $this->systemHandler->handleLoggingSetLevel($params, $id, $sessionId, $context, $response);

                case 'completion/complete':
                case 'completions/complete':
                    return $this->toolsHandler->handleCompletionsComplete($params, $id, $sessionId, $context, $response);

                case 'elicitation/create':
                    return $this->samplingHandler->handleElicitationRequest($params, $id, $sessionId, $context, $response);

                case 'sampling/createMessage':
                    return $this->samplingHandler->handleSamplingResponse($params, $id, $sessionId, $context, $response);

                case 'roots/list':
                    return $this->samplingHandler->handleRootsListResponse($params, $id, $sessionId, $context, $response);

                case 'roots/read':
                case 'roots/listDirectory':
                    return $this->samplingHandler->handleRootsReadResponse($params, $id, $sessionId, $context, $response);

                default:
                    if (!$sessionId) {
                        throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
                    }
                    return $this->responseManager->storeErrorResponse($sessionId, -32601, "Method not found: {$method}. This server serves the methods listed in the capabilities returned by initialize.", $id, $response);
            }
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Exception $e) {
            if (!$sessionId) {
                throw new ProtocolException('Internal error: ' . $e->getMessage(), -32603);
            }
            return $this->responseManager->storeErrorResponse($sessionId, -32603, "Internal error handling {$method}: " . $e->getMessage(), $id, $response);
        }
    }

    private function processNotification(string $method, array $params, ?string $sessionId, string $protocolVersion): void
    {
        switch ($method) {
            case 'initialized':
            case 'notifications/initialized':
                break;

            case 'notifications/cancelled':
                if ($sessionId && isset($params['requestId'])) {
                    $messages = $this->storage->getMessages($sessionId);
                    foreach ($messages as $message) {
                        if (($message['data']['id'] ?? null) === $params['requestId']) {
                            $this->storage->deleteMessage($message['id']);
                        }
                    }
                }
                break;

            case 'notifications/progress':
                break;
        }
    }
}
