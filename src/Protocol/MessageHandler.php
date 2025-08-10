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
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private StorageInterface $storage;
    private array $config;
    private array $sessionVersionCache = [];
    private array $sessionRequestIds = [];
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

        // Initialize delegated handlers
        $this->protocolManager = new ProtocolManager($storage, $config);
        $this->contentProcessor = new ContentProcessor($this->protocolManager);
        $this->responseManager = new ResponseManager($storage);

        $this->toolsHandler = new ToolsHandler(
            $this->toolRegistry,
            $this->promptRegistry,
            $this->protocolManager,
            $this->contentProcessor,
            $this->responseManager
        );

        $this->promptsHandler = new PromptsHandler(
            $this->promptRegistry,
            $this->responseManager
        );

        $this->resourcesHandler = new ResourcesHandler(
            $this->resourceRegistry,
            $this->responseManager
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

        // MCP 2025-06-18 requires protocol version header validation (skip for authless)
        if ($protocolVersion === '2025-06-18' && !($context['authless'] ?? false)) {
            if (!isset($context['protocol_version']) || $context['protocol_version'] !== $protocolVersion) {
                throw new ProtocolException('MCP-Protocol-Version header required and must match negotiated version for 2025-06-18', -32600);
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

    public function sendProgressNotification(string $sessionId, int $progress, string $message = ''): void
    {
        if (!isset($this->sessionVersionCache[$sessionId])) {
            $this->sessionVersionCache[$sessionId] =
                $this->protocolManager->getSessionVersion($sessionId);
        }
        $protocolVersion = $this->sessionVersionCache[$sessionId];

        if (!$this->protocolManager->isFeatureSupported('progress_notifications', $protocolVersion)) {
            return;
        }

        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => [
                'progress' => $progress,
                'total' => 100
            ]
        ];

        // Message field only supported in 2025-03-26+
        if ($this->protocolManager->isFeatureSupported('progress_messages', $protocolVersion)) {
            $notification['params']['message'] = $message;
        }

        $this->storage->storeMessage($sessionId, $notification);
    }

    public function requestElicitation(
        string $sessionId,
        string $message,
        ?array $requestedSchema = null,
        array $context = []
    ): string {
        return $this->samplingHandler->requestElicitation($sessionId, $message, $requestedSchema, $context);
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

        foreach ($batchData as $index => $requestData) {
            if (!is_array($requestData)) {
                throw new ProtocolException('Invalid Request: batch item must be object', -32600);
            }

            try {
                $singleResponse = $this->processSingleMessage($requestData, $sessionId, $context, $response, $protocolVersion);

                $hasId = array_key_exists('id', $requestData);
                $isNotification = !$hasId || $requestData['id'] === null;

                if (!$isNotification) {
                    $responseBody = $singleResponse->getBody()->getContents();
                    $responseData = json_decode($responseBody, true);

                    if ($responseData && (isset($responseData['result']) || isset($responseData['error']))) {
                        $batchResponses[] = $responseData;
                    }
                } else {
                    $hasNotifications = true;
                }
            } catch (ProtocolException $e) {
                $batchResponses[] = [
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => '',
                        'message' => ''
                    ],
                    'id' => $requestData['id'] ?? null
                ];
            }
        }

        // All notifications = 202 with no body
        if (empty($batchResponses) && $hasNotifications) {
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withStatus(202);
        }

        $response->getBody()->write(json_encode($batchResponses));
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
            throw new ProtocolException('Invalid Request', -32600);
        }

        if (!isset($data['method'])) {
            throw new ProtocolException('Invalid Request', -32600);
        }

        $method = $data['method'];
        $params = $data['params'] ?? [];

        $hasId = array_key_exists('id', $data);
        $id = $hasId ? $data['id'] : null;

        $isExplicitNotification = str_starts_with($method, 'notifications/') || in_array($method, ['initialized']);

        if (!$isExplicitNotification && (!$hasId || $data['id'] === null)) {
            throw new ProtocolException('Request id cannot be null', -32600);
        }

        $isNotification = $isExplicitNotification || !$hasId;

        // Skip version gating for initialize method
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

        // Prevent duplicate request IDs per session
        if (!$isNotification && $sessionId !== null && $id !== null) {
            if (!isset($this->sessionRequestIds[$sessionId])) {
                $this->sessionRequestIds[$sessionId] = [];
            }
            if (in_array($id, $this->sessionRequestIds[$sessionId])) {
                throw new ProtocolException('Duplicate request id', -32600);
            }
            $this->sessionRequestIds[$sessionId][] = $id;
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
                    return $this->toolsHandler->handleToolsList($id, $sessionId, $context, $response);

                case 'tools/call':
                    return $this->toolsHandler->handleToolsCall($params, $id, $sessionId, $context, $response);

                case 'prompts/list':
                    return $this->promptsHandler->handlePromptsList($id, $sessionId, $context, $response);

                case 'prompts/get':
                    return $this->promptsHandler->handlePromptsGet($params, $id, $sessionId, $context, $response);

                case 'resources/list':
                    return $this->resourcesHandler->handleResourcesList($id, $sessionId, $context, $response);

                case 'resources/read':
                    return $this->resourcesHandler->handleResourcesRead($params, $id, $sessionId, $context, $response);

                case 'resources/templates/list':
                    return $this->resourcesHandler->handleResourceTemplatesList($id, $sessionId, $context, $response);

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
                        throw new ProtocolException('Session required', -32001);
                    }
                    return $this->responseManager->storeErrorResponse($sessionId, -32601, 'Method not found', $id, $response);
            }
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Exception $e) {
            if (!$sessionId) {
                throw new ProtocolException('Internal error: ' . $e->getMessage(), -32603);
            }
            return $this->responseManager->storeErrorResponse($sessionId, -32603, 'Internal error', $id, $response);
        }
    }

    private function processNotification(string $method, array $params, ?string $sessionId, string $protocolVersion): void
    {
        switch ($method) {
            case 'initialized':
            case 'notifications/initialized':
                break;

            case 'notifications/cancelled':
                if ($sessionId) {
                    $messages = $this->storage->getMessages($sessionId);
                    foreach ($messages as $message) {
                        $this->storage->deleteMessage($message['id']);
                    }
                }
                break;

            case 'notifications/progress':
                break;
        }
    }
}
