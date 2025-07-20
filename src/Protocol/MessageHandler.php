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

        $serverInfo = $this->config['server_info'] ?? [
        'name' => 'WaaSuP MCP SaaS Server',
        'version' => '1.0.0'
        ];

        $result = [
        'protocolVersion' => $selectedVersion,
        'capabilities' => [
            'tools' => [
                'listChanged' => true
            ],
            'prompts' => [
                'listChanged' => true
            ],
            'resources' => [
                'subscribe' => false,
                'listChanged' => true
            ]
        ],
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

        $result = $this->toolRegistry->getToolsList();
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

            $wrappedResult = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT)
                ]
            ]
            ];

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
