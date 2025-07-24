<?php

namespace Seolinkmap\Waasup\Protocol;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Content\AudioContentHandler;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
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
    private VersionNegotiator $versionNegotiator;
    private array $sessionRequestIds = [];

    // MCP spec feature matrix - gates features by protocol version
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
            'resource_indicators' => false
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
            'resource_indicators' => false
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
            'resource_indicators' => true
        ]
    ];

    public function __construct(
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        StorageInterface $storage,
        array $config = [],
        ?VersionNegotiator $versionNegotiator = null
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->resourceRegistry = $resourceRegistry;
        $this->storage = $storage;
        $this->config = $config;
        $this->versionNegotiator = $versionNegotiator ?? new VersionNegotiator($config['supported_versions'] ?? ['2025-06-18', '2025-03-26', '2024-11-05']);
    }

    public function processMessage(
        array $data,
        ?string $sessionId,
        array $context,
        Response $response
    ): Response {
        $protocolVersion = $this->getSessionVersion($sessionId);

        // MCP 2025-06-18 requires protocol version header validation (skip for authless)
        if ($protocolVersion === '2025-06-18' && !($context['authless'] ?? false)) {
            if (!isset($context['protocol_version']) || $context['protocol_version'] !== $protocolVersion) {
                throw new ProtocolException('MCP-Protocol-Version header required and must match negotiated version for 2025-06-18', -32600);
            }
        }

        if ($this->isBatchRequest($data)) {
            if (!$this->isFeatureSupported('json_rpc_batching', $protocolVersion)) {
                throw new ProtocolException('JSON-RPC batching not supported in this protocol version', -32600);
            }
            return $this->processBatchRequest($data, $sessionId, $context, $response, $protocolVersion);
        }

        return $this->processSingleMessage($data, $sessionId, $context, $response, $protocolVersion);
    }

    private function isBatchRequest(array $data): bool
    {
        return array_keys($data) === range(0, count($data) - 1);
    }

    // JSON-RPC batching only supported in 2025-03-26+
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
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
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
            if (!$this->isMethodSupported($method, $protocolVersion)) {
                if ($isNotification) {
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withHeader('Access-Control-Allow-Origin', '*')
                        ->withStatus(202);
                } else {
                    return $this->storeErrorResponse($sessionId, -32601, "Method not supported in protocol version {$protocolVersion}", $id, $response);
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
                    return $this->handleInitialize($params, $id, $sessionId, $response);

                case 'ping':
                    return $this->handlePing($id, $sessionId, $context, $response);

                case 'tools/list':
                    return $this->handleToolsList($id, $sessionId, $context, $response);

                case 'tools/call':
                    return $this->handleToolsCall($params, $id, $sessionId, $context, $response);

                case 'prompts/list':
                    return $this->handlePromptsList($id, $sessionId, $context, $response);

                case 'prompts/get':
                    return $this->handlePromptsGet($params, $id, $sessionId, $context, $response);

                case 'resources/list':
                    return $this->handleResourcesList($id, $sessionId, $context, $response);

                case 'resources/read':
                    return $this->handleResourcesRead($params, $id, $sessionId, $context, $response);

                case 'resources/templates/list':
                    return $this->handleResourceTemplatesList($id, $sessionId, $context, $response);

                case 'completions/complete':
                    return $this->handleCompletionsComplete($params, $id, $sessionId, $context, $response);

                case 'elicitation/create':
                    return $this->handleElicitationRequest($params, $id, $sessionId, $context, $response);

                case 'sampling/createMessage':
                    return $this->handleSamplingResponse($params, $id, $sessionId, $context, $response);

                case 'roots/list':
                    return $this->handleRootsListResponse($params, $id, $sessionId, $context, $response);

                case 'roots/read':
                case 'roots/listDirectory':
                    return $this->handleRootsReadResponse($params, $id, $sessionId, $context, $response);

                default:
                    if (!$sessionId) {
                        throw new ProtocolException('Session required', -32001);
                    }
                    return $this->storeErrorResponse($sessionId, -32601, 'Method not found', $id, $response);
            }
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Exception $e) {
            if (!$sessionId) {
                throw new ProtocolException('Internal error: ' . $e->getMessage(), -32603);
            }
            return $this->storeErrorResponse($sessionId, -32603, 'Internal error', $id, $response);
        }
    }

    private function isMethodSupported(string $method, string $protocolVersion): bool
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
            'completions/complete' => 'completions',
            'elicitation/create' => 'elicitation',
            'sampling/createMessage' => 'sampling',
            'roots/list' => 'roots',
            'roots/read' => 'roots',
            'roots/listDirectory' => 'roots',
            'notifications/initialized' => 'tools',
            'notifications/cancelled' => 'tools',
            'notifications/progress' => 'progress_notifications'
        ];

        $feature = $methodFeatureMap[$method] ?? null;
        if (!$feature) {
            return false;
        }

        return $this->isFeatureSupported($feature, $protocolVersion);
    }

    private function isFeatureSupported(string $feature, string $protocolVersion): bool
    {
        return self::FEATURE_MATRIX[$protocolVersion][$feature] ?? false;
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

    public function handleInitialize(array $params, mixed $id, ?string $sessionId, Response $response): Response
    {
        $selectedVersion = $params['protocolVersion'] ?? null;
        if ($sessionId) {
            $this->storeSessionVersion($sessionId, $selectedVersion);
        }

        $serverInfo = $this->config['server_info'] ?? [
        'name' => 'WaaSuP MCP SaaS Server',
        'version' => '0.0.7'
        ];

        $capabilities = [];

        if ($this->isFeatureSupported('tools', $selectedVersion)) {
            $capabilities['tools'] = ['listChanged' => true];
        }

        if ($this->isFeatureSupported('prompts', $selectedVersion)) {
            $capabilities['prompts'] = ['listChanged' => true];
        }

        if ($this->isFeatureSupported('resources', $selectedVersion)) {
            $capabilities['resources'] = ['subscribe' => false, 'listChanged' => true];
        }

        if ($this->isFeatureSupported('completions', $selectedVersion)) {
            $capabilities['completions'] = true;
        }

        if ($this->isFeatureSupported('elicitation', $selectedVersion)) {
            $capabilities['elicitation'] = (object)[];
        }

        if ($this->isFeatureSupported('sampling', $selectedVersion)) {
            $capabilities['sampling'] = (object)[];
        }

        if ($this->isFeatureSupported('roots', $selectedVersion)) {
            $capabilities['roots'] = ['listChanged' => true];
        }

        $result = [
        'protocolVersion' => $selectedVersion,
        'capabilities' => $capabilities,
        'serverInfo' => $serverInfo
        ];

        $responseData = [
        'jsonrpc' => '2.0',
        'result' => $result,
        'id' => $id
        ];

        $response->getBody()->write(json_encode($responseData));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $this->sanitizeHeaderValue($sessionId))
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->withStatus(200);
    }

    public function requestSampling(
        string $sessionId,
        array $messages,
        array $options = [],
        array $context = []
    ): string {
        $requestId = bin2hex(random_bytes(16));

        $samplingRequest = [
        'jsonrpc' => '2.0',
        'method' => 'sampling/createMessage',
        'id' => $requestId,
        'params' => [
            'messages' => $messages,
            'includeContext' => $options['includeContext'] ?? 'none',
            'temperature' => $options['temperature'] ?? null,
            'maxTokens' => $options['maxTokens'] ?? null,
            'stopSequences' => $options['stopSequences'] ?? null,
            'metadata' => $options['metadata'] ?? []
        ]
        ];

        $this->storage->storeMessage($sessionId, $samplingRequest, $context);

        return $requestId;
    }

    // Audio content processing only for 2025-03-26+
    private function processContentWithAudio(array $content, string $protocolVersion): array
    {
        $processedContent = [];

        foreach ($content as $item) {
            if (!isset($item['type'])) {
                throw new ProtocolException('Content item missing type', -32602);
            }

            switch ($item['type']) {
                case 'text':
                    $processedContent[] = [
                        'type' => 'text',
                        'text' => $item['text'] ?? ''
                    ];
                    break;

                case 'image':
                    $processedContent[] = [
                        'type' => 'image',
                        'data' => $item['data'] ?? '',
                        'mimeType' => $item['mimeType'] ?? 'image/jpeg'
                    ];
                    break;

                case 'audio':
                    if (!$this->isFeatureSupported('audio_content', $protocolVersion)) {
                        throw new ProtocolException("Audio content not supported in version {$protocolVersion}", -32602);
                    }

                    try {
                        $processedContent[] = AudioContentHandler::processAudioContent($item);
                    } catch (\Exception $e) {
                        throw new ProtocolException("Invalid audio content: " . $e->getMessage(), -32602);
                    }
                    break;

                default:
                    throw new ProtocolException("Unsupported content type: {$item['type']}", -32602);
            }
        }

        return $processedContent;
    }

    private function handleSamplingResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $samplingResult = [
        'requestId' => $id,
        'model' => $params['model'] ?? null,
        'stopReason' => $params['stopReason'] ?? null,
        'role' => $params['role'] ?? 'assistant',
        'content' => $params['content'] ?? []
        ];

        $resultData = [
        'type' => 'sampling_response',
        'result' => $samplingResult,
        'timestamp' => time()
        ];

        $this->storage->storeSamplingResponse($sessionId, $id, $resultData);

        return $this->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    public function requestRootsList(string $sessionId, array $context = []): string
    {
        $requestId = bin2hex(random_bytes(16));

        $rootsRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/list',
        'id' => $requestId,
        'params' => []
        ];

        $this->storage->storeMessage($sessionId, $rootsRequest, $context);

        return $requestId;
    }

    public function requestRootsRead(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        $requestId = bin2hex(random_bytes(16));

        $readRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/read',
        'id' => $requestId,
        'params' => array_merge(['uri' => $uri], $options)
        ];

        $this->storage->storeMessage($sessionId, $readRequest, $context);

        return $requestId;
    }

    public function requestRootsListDirectory(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        $requestId = bin2hex(random_bytes(16));

        $listRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/listDirectory',
        'id' => $requestId,
        'params' => array_merge(['uri' => $uri], $options)
        ];

        $this->storage->storeMessage($sessionId, $listRequest, $context);

        return $requestId;
    }

    private function handleRootsListResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $rootsResult = [
        'requestId' => $id,
        'roots' => $params['roots'] ?? []
        ];

        $resultData = [
        'type' => 'roots_list_response',
        'result' => $rootsResult,
        'timestamp' => time()
        ];

        $this->storage->storeRootsResponse($sessionId, $id, $resultData);

        return $this->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    private function handleRootsReadResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $readResult = [
        'requestId' => $id,
        'contents' => $params['contents'] ?? []
        ];

        $resultData = [
        'type' => 'roots_read_response',
        'result' => $readResult,
        'timestamp' => time()
        ];

        $this->storage->storeRootsResponse($sessionId, $id, $resultData);

        return $this->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

        /**
     * Get protocol version from session data (authoritative source)
     */
    private function getSessionVersion(?string $sessionId): string
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionData = $this->storage->getSession($sessionId);

        if (!$sessionData) {
            throw new ProtocolException('Invalid or expired session ID', -32001);
        }

        // Use stored protocol version as authoritative source
        if (isset($sessionData['protocol_version'])) {
            return $sessionData['protocol_version'];
        }

        // Fallback to extracting from sessionId format (protocolVersion_sessionId)
        if (strpos($sessionId, '_') !== false) {
            $parts = explode('_', $sessionId, 2);
            if (count($parts) === 2 && in_array($parts[0], $this->config['supported_versions'] ?? [])) {
                return $parts[0];
            }
        }

        throw new ProtocolException('No protocol version found in session', -32001);
    }

    /**
     * Store protocol version in session data
     */
    private function storeSessionVersion(string $sessionId, string $version): void
    {
        // Get existing session data to preserve other values
        $existingData = $this->storage->getSession($sessionId) ?? [];

        // Update with new protocol version
        $sessionData = array_merge($existingData, [
            'protocol_version' => $version,
            'updated_at' => time()
        ]);

        $this->storage->storeSession($sessionId, $sessionData);
    }

    private function handleCompletionsComplete(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $ref = $params['ref'] ?? null;
        $argument = $params['argument'] ?? null;

        if (!$ref) {
            return $this->storeErrorResponse($sessionId, -32602, 'Invalid params: missing ref', $id, $response);
        }

        try {
            $completions = $this->generateCompletions($ref, $argument, $context);
            $result = ['completions' => $completions];
            return $this->storeSuccessResponse($sessionId, $result, $id, $response);
        } catch (\Exception $e) {
            return $this->storeErrorResponse($sessionId, -32603, 'Completion generation failed', $id, $response);
        }
    }

    // Elicitation only supported in 2025-06-18
    private function handleElicitationRequest(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionVersion = $this->getSessionVersion($sessionId);
        if (!$this->isFeatureSupported('elicitation', $sessionVersion)) {
            return $this->storeErrorResponse($sessionId, -32601, 'Elicitation not supported in this protocol version', $id, $response);
        }

        $prompt = $params['message'] ?? '';
        $requestedSchema = $params['requestedSchema'] ?? null;

        $elicitationData = [
        'type' => 'elicitation',
        'prompt' => $prompt,
        'requestedSchema' => $requestedSchema,
        'requestId' => $id
        ];

        return $this->storeSuccessResponse($sessionId, $elicitationData, $id, $response);
    }

    public function requestElicitation(
        string $sessionId,
        string $message,
        ?array $requestedSchema = null,
        array $context = []
    ): string {
        $requestId = bin2hex(random_bytes(16));

        $elicitationRequest = [
        'jsonrpc' => '2.0',
        'method' => 'elicitation/create',
        'id' => $requestId,
        'params' => [
            'message' => $message,
            'requestedSchema' => $requestedSchema
        ]
        ];

        $this->storage->storeMessage($sessionId, $elicitationRequest, $context);

        return $requestId;
    }

    private function generateCompletions(array $ref, ?string $argument, array $context): array
    {
        $completions = [];

        if ($ref['type'] === 'ref/tool') {
            $toolName = $ref['name'];
            if ($this->toolRegistry->hasTool($toolName)) {
                $completions = $this->generateToolCompletions($toolName, $argument);
            }
        } elseif ($ref['type'] === 'ref/prompt') {
            $promptName = $ref['name'];
            if ($this->promptRegistry->hasPrompt($promptName)) {
                $completions = $this->generatePromptCompletions($promptName, $argument);
            }
        }

        return $completions;
    }

    private function generateToolCompletions(string $toolName, ?string $argument): array
    {
        return [
        ['value' => 'example_value', 'description' => 'Example completion'],
        ];
    }

    private function generatePromptCompletions(string $promptName, ?string $argument): array
    {
        return [
        ['value' => 'example_arg', 'description' => 'Example argument'],
        ];
    }

    // Progress notifications with version-aware message field
    public function sendProgressNotification(string $sessionId, int $progress, string $message = ''): void
    {
        $protocolVersion = $this->getSessionVersion($sessionId);

        if (!$this->isFeatureSupported('progress_notifications', $protocolVersion)) {
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
        if ($this->isFeatureSupported('progress_messages', $protocolVersion)) {
            $notification['params']['message'] = $message;
        }

        $this->storage->storeMessage($sessionId, $notification);
    }

    private function handlePing(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = [
            'status' => 'pong',
            'timestamp' => date('c')
        ];

        return $this->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    private function handleToolsList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionVersion = $this->getSessionVersion($sessionId);
        $result = $this->toolRegistry->getToolsList($sessionVersion);

        return $this->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    private function handleToolsCall(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            return $this->storeErrorResponse($sessionId, -32602, 'Invalid params: missing tool name', $id, $response);
        }

        try {
            $result = $this->toolRegistry->execute($toolName, $arguments, $context);

            $sessionVersion = $this->getSessionVersion($sessionId);

            if (isset($result['content']) && is_array($result['content'])) {
                $result['content'] = $this->processContentWithAudio($result['content'], $sessionVersion);
            }

            // Structured outputs only in 2025-06-18
            if ($this->isFeatureSupported('structured_outputs', $sessionVersion)) {
                if (isset($result['_meta']) && $result['_meta']['structured'] === true) {
                    $wrappedResult = [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result['data'], JSON_PRETTY_PRINT)
                        ]
                    ],
                    'structuredContent' => $result['data']
                    ];

                    // Resource links only in 2025-06-18
                    if ($this->isFeatureSupported('resource_links', $sessionVersion)) {
                        if (isset($result['_meta']['resourceLinks'])) {
                            $wrappedResult['resourceLinks'] = $result['_meta']['resourceLinks'];
                        }
                    }
                } else {
                    $wrappedResult = [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_PRETTY_PRINT)
                        ]
                    ]
                    ];
                }
            } else {
                $wrappedResult = [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ]
                ];
            }

            return $this->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
        } catch (\Exception $e) {
            error_log("Tool execution error: {$toolName} - " . $e->getMessage());

            $errorResult = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Tool execution failed'
                ]
            ]
            ];

            return $this->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }

    private function handlePromptsList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->promptRegistry->getPromptsList();
        return $this->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    private function handlePromptsGet(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $promptName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($promptName)) {
            return $this->storeErrorResponse($sessionId, -32602, 'Invalid params: missing prompt name', $id, $response);
        }

        try {
            $result = $this->promptRegistry->execute($promptName, $arguments, $context);

            $wrappedResult = [
                'description' => $result['description'] ?? '',
                'messages' => $result['messages'] ?? []
            ];

            return $this->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
        } catch (\Exception $e) {
            error_log("Prompt execution error: {$promptName} - " . $e->getMessage());

            $errorResult = [
                'description' => '',
                'messages' => [
                    [
                        'role' => 'assistant',
                        'content' => [
                            'type' => 'text',
                            'text' => 'Prompt execution failed'
                        ]
                    ]
                ]
            ];

            return $this->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }

    private function handleResourcesList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->resourceRegistry->getResourcesList();
        return $this->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    private function handleResourcesRead(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $uri = $params['uri'] ?? '';

        if (empty($uri)) {
            return $this->storeErrorResponse($sessionId, -32602, 'Invalid params: missing resource uri', $id, $response);
        }

        try {
            $result = $this->resourceRegistry->read($uri, $context);

            $wrappedResult = [
                'contents' => $result['contents'] ?? []
            ];

            return $this->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
        } catch (\Exception $e) {
            error_log("Resource read error: {$uri} - " . $e->getMessage());

            $errorResult = [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/plain',
                        'text' => 'Resource read failed'
                    ]
                ]
            ];

            return $this->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }

    private function handleResourceTemplatesList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->resourceRegistry->getResourceTemplatesList();
        return $this->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    private function storeSuccessResponse(string $sessionId, mixed $result, mixed $id, Response $response): Response
    {
        $responseData = [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ];

        $this->storage->storeMessage($sessionId, $responseData);

        $response->getBody()->write('{"status": "queued"}');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withStatus(202);
    }

    private function storeErrorResponse(
        string $sessionId,
        int $code,
        string $message,
        mixed $id,
        Response $response
    ): Response {
        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'id' => $id
        ];

        $this->storage->storeMessage($sessionId, $responseData);

        $response->getBody()->write('{"status": "queued"}');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withStatus(202);
    }

    private function sanitizeHeaderValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/[^\x21-\x7E\x80-\xFF]/', '', $value);
    }
}
