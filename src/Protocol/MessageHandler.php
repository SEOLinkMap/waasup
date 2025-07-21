<?php

namespace Seolinkmap\Waasup\Protocol;

use Psr\Http\Message\ResponseInterface as Response;
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
        $this->versionNegotiator = $versionNegotiator ?? new VersionNegotiator($config['supported_versions'] ?? ['2025-03-18', '2024-11-05']);
    }

    public function processMessage(
        array $data,
        ?string $sessionId,
        array $context,
        Response $response
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
            $this->processNotification($method, $params, $sessionId);
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

    private function processNotification(string $method, array $params, ?string $sessionId): void
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

    private function handleInitialize(array $params, mixed $id, ?string $sessionId, Response $response): Response
    {
        $clientProtocolVersion = $params['protocolVersion'] ?? null;
        if ($clientProtocolVersion === null) {
            throw new ProtocolException('Invalid params: protocolVersion required', -32602);
        }

        $selectedVersion = $this->versionNegotiator->negotiate($clientProtocolVersion);

        // CRITICAL: Store the negotiated version for this session
        if ($sessionId) {
            $this->storeSessionVersion($sessionId, $selectedVersion);
        }

        $serverInfo = $this->config['server_info'] ?? [
        'name' => 'WaaSuP MCP SaaS Server',
        'version' => '1.1.0'
        ];

        $capabilities = [
        'tools' => ['listChanged' => true],
        'prompts' => ['listChanged' => true],
        'resources' => ['subscribe' => false, 'listChanged' => true]
        ];

        // Version-specific capabilities - only add if supported in negotiated version
        if ($this->isFeatureSupported('completions', $selectedVersion)) {
            $capabilities['completions'] = true;
        }

        if ($this->isFeatureSupported('elicitation', $selectedVersion)) {
            $capabilities['elicitation'] = true;
        }

        if ($this->isFeatureSupported('sampling', $selectedVersion)) {
            $capabilities['sampling'] = [];
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

    private function isFeatureSupported(string $feature, string $version): bool
    {
        $featureMatrix = [
            'tool_annotations' => ['2025-03-26', '2025-06-18'],
            'audio_content' => ['2025-03-26', '2025-06-18'],
            'structured_outputs' => ['2025-06-18'],
            'elicitation' => ['2025-06-18'],
            'resource_links' => ['2025-06-18'],
            'progress_messages' => ['2025-03-26', '2025-06-18'],
            'completions' => ['2025-03-26', '2025-06-18'],
            'sampling' => ['2024-11-05', '2025-03-26', '2025-06-18'],
            'roots' => ['2024-11-05', '2025-03-26', '2025-06-18']
        ];

        return in_array($version, $featureMatrix[$feature] ?? []);
    }

    /**
     * Initiate a sampling request to the client
     */
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

        // Store the request for tracking
        $this->storage->storeMessage($sessionId, $samplingRequest, $context);

        return $requestId;
    }

    private function handleSamplingResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        // This would be called when client responds to our sampling request
        // Store the response for the application to process
        $samplingResult = [
        'requestId' => $id,
        'model' => $params['model'] ?? null,
        'stopReason' => $params['stopReason'] ?? null,
        'role' => $params['role'] ?? 'assistant',
        'content' => $params['content'] ?? []
        ];

        // Store result with special marker for sampling responses
        $resultData = [
        'type' => 'sampling_response',
        'result' => $samplingResult,
        'timestamp' => time()
        ];

        $this->storage->storeSamplingResponse($sessionId, $id, $resultData);

        return $this->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    /**
     * Request roots list from connected client
     */
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

    /**
     * Request file/directory operations from client roots
     */
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

    /**
     * Request directory listing from client roots
     */
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

    private function getSessionVersion(?string $sessionId): string
    {
        if (!$sessionId) {
            return '2024-11-05'; // fallback to oldest version
        }

        // Get negotiated version from session storage
        $sessionData = $this->storage->getSession($sessionId);
        return $sessionData['protocol_version'] ?? '2024-11-05'; // Default to oldest, not newest
    }

    private function storeSessionVersion(string $sessionId, string $version): void
    {
        $sessionData = ['protocol_version' => $version, 'initialized_at' => time()];
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

    private function handleElicitationRequest(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $prompt = $params['message'] ?? '';
        $options = $params['requestedSchema'] ?? null;

        $elicitationData = [
        'type' => 'elicitation',
        'prompt' => $prompt,
        'options' => $options,
        'requestId' => $id
        ];

        return $this->storeSuccessResponse($sessionId, $elicitationData, $id, $response);
    }

    private function generateCompletions(array $ref, ?string $argument, array $context): array
    {
        $completions = [];

        if ($ref['type'] === 'ref/tool') {
            $toolName = $ref['name'];
            if ($this->toolRegistry->hasTool($toolName)) {
                // Generate argument completions for the tool
                $completions = $this->generateToolCompletions($toolName, $argument);
            }
        } elseif ($ref['type'] === 'ref/prompt') {
            $promptName = $ref['name'];
            if ($this->promptRegistry->hasPrompt($promptName)) {
                // Generate argument completions for the prompt
                $completions = $this->generatePromptCompletions($promptName, $argument);
            }
        }

        return $completions;
    }

    private function generateToolCompletions(string $toolName, ?string $argument): array
    {
        // Implementation would depend on tool's input schema
        return [
        ['value' => 'example_value', 'description' => 'Example completion'],
        ];
    }

    private function generatePromptCompletions(string $promptName, ?string $argument): array
    {
        // Implementation would depend on prompt's argument schema
        return [
        ['value' => 'example_arg', 'description' => 'Example argument'],
        ];
    }

    public function sendProgressNotification(string $sessionId, int $progress, string $message = ''): void
    {
        $notification = [
        'jsonrpc' => '2.0',
        'method' => 'notifications/progress',
        'params' => [
            'progress' => $progress,
            'total' => 100
        ]
        ];

        // Add message field for 2025-03-26+
        if ($this->isFeatureSupported('progress_messages', $this->getSessionVersion($sessionId))) {
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
        $result = $this->toolRegistry->getToolsList($sessionVersion); // Pass version to registry

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

            // Support structured outputs (2025-06-18+)
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

                    // Resource links support
                    if (isset($result['_meta']['resourceLinks'])) {
                        $wrappedResult['resourceLinks'] = $result['_meta']['resourceLinks'];
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
                // Legacy format for older versions
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
