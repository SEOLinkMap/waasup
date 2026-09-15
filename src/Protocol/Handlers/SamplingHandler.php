<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Storage\StorageInterface;

class SamplingHandler
{
    private StorageInterface $storage;
    private ResponseManager $responseManager;
    private ProtocolManager $protocolManager;

    public function __construct(
        StorageInterface $storage,
        ResponseManager $responseManager,
        ProtocolManager $protocolManager
    ) {
        $this->storage = $storage;
        $this->responseManager = $responseManager;
        $this->protocolManager = $protocolManager;
    }

    public function requestSampling(
        string $sessionId,
        array $messages,
        array $options = [],
        array $context = []
    ): string {
        $this->requireClientCapability($sessionId, 'sampling');

        $requestId = bin2hex(random_bytes(16));

        $params = [
            'messages' => $messages,
            'maxTokens' => $options['maxTokens'] ?? 1000,
            'includeContext' => $options['includeContext'] ?? 'none'
        ];

        foreach (['temperature', 'stopSequences', 'metadata', 'systemPrompt', 'modelPreferences'] as $option) {
            if (isset($options[$option])) {
                $params[$option] = $options[$option];
            }
        }

        if ($this->protocolManager->isFeatureSupported('sampling_tools', $this->protocolManager->getSessionVersion($sessionId))) {
            foreach (['tools', 'toolChoice'] as $option) {
                if (isset($options[$option])) {
                    $params[$option] = $options[$option];
                }
            }
        }

        $samplingRequest = [
        'jsonrpc' => '2.0',
        'method' => 'sampling/createMessage',
        'id' => $requestId,
        'params' => $params
        ];

        $this->storage->storeMessage($sessionId, $samplingRequest, $context);
        $this->rememberRequest($sessionId, $requestId, 'sampling/createMessage');

        return $requestId;
    }

    public function handleSamplingResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    public function requestRootsList(string $sessionId, array $context = []): string
    {
        $this->requireClientCapability($sessionId, 'roots');

        $requestId = bin2hex(random_bytes(16));

        $rootsRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/list',
        'id' => $requestId,
        'params' => []
        ];

        $this->storage->storeMessage($sessionId, $rootsRequest, $context);
        $this->rememberRequest($sessionId, $requestId, 'roots/list');

        return $requestId;
    }

    public function requestRootsRead(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        $this->requireClientCapability($sessionId, 'roots');

        $requestId = bin2hex(random_bytes(16));

        $readRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/read',
        'id' => $requestId,
        'params' => array_merge(['uri' => $uri], $options)
        ];

        $this->storage->storeMessage($sessionId, $readRequest, $context);
        $this->rememberRequest($sessionId, $requestId, 'roots/read');

        return $requestId;
    }

    public function requestRootsListDirectory(
        string $sessionId,
        string $uri,
        array $options = [],
        array $context = []
    ): string {
        $this->requireClientCapability($sessionId, 'roots');

        $requestId = bin2hex(random_bytes(16));

        $listRequest = [
        'jsonrpc' => '2.0',
        'method' => 'roots/listDirectory',
        'id' => $requestId,
        'params' => array_merge(['uri' => $uri], $options)
        ];

        $this->storage->storeMessage($sessionId, $listRequest, $context);
        $this->rememberRequest($sessionId, $requestId, 'roots/listDirectory');

        return $requestId;
    }

    public function handleRootsListResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    public function handleRootsReadResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    /**
     * Assert the client declared a capability at initialize
     *
     * @throws ProtocolException when the capability was not declared
     */
    private function requireClientCapability(string $sessionId, string $capability): void
    {
        $capabilities = $this->protocolManager->getSessionValue($sessionId, 'client_capabilities', []);

        if (!isset($capabilities[$capability])) {
            throw new ProtocolException(
                "This client did not declare the '{$capability}' capability when it initialized, so the server cannot send it a {$capability} request.",
                -32601
            );
        }
    }

    /**
     * Record an outstanding server to client request
     *
     * @param string $method the method the client will answer
     */
    private function rememberRequest(string $sessionId, string $requestId, string $method): void
    {
        $pending = $this->protocolManager->getSessionValue($sessionId, 'pending_requests', []);
        $pending[$requestId] = $method;

        if (count($pending) > 50) {
            $pending = array_slice($pending, -50, null, true);
        }

        $this->protocolManager->storeSessionValue($sessionId, 'pending_requests', $pending);
    }

    /**
     * Store a client response to a server initiated request
     *
     * @param array $payload the response 'result', or 'error' when the client refused
     * @return bool true when the id matched an outstanding request
     */
    public function storeClientResponse(string $sessionId, mixed $id, array $payload): bool
    {
        $pending = $this->protocolManager->getSessionValue($sessionId, 'pending_requests', []);
        $requestId = (string)$id;
        $method = $pending[$requestId] ?? null;

        if ($method === null) {
            return false;
        }

        unset($pending[$requestId]);
        $this->protocolManager->storeSessionValue($sessionId, 'pending_requests', $pending);

        switch ($method) {
            case 'sampling/createMessage':
                $this->storage->storeSamplingResponse(
                    $sessionId,
                    $requestId,
                    [
                        'type' => 'sampling_response',
                        'result' => array_replace_recursive(['requestId' => $id], $payload),
                        'timestamp' => time()
                    ]
                );
                break;

            case 'roots/list':
                $this->storage->storeRootsResponse(
                    $sessionId,
                    $requestId,
                    [
                        'type' => 'roots_list_response',
                        'result' => array_replace_recursive(['requestId' => $id], $payload),
                        'timestamp' => time()
                    ]
                );
                break;

            case 'roots/read':
            case 'roots/listDirectory':
                $this->storage->storeRootsResponse(
                    $sessionId,
                    $requestId,
                    [
                        'type' => 'roots_read_response',
                        'result' => array_replace_recursive(['requestId' => $id], $payload),
                        'timestamp' => time()
                    ]
                );
                break;

            case 'elicitation/create':
                $this->storage->storeElicitationResponse(
                    $sessionId,
                    $requestId,
                    [
                        'type' => 'elicitation_response',
                        'result' => array_replace_recursive(['requestId' => $id], $payload),
                        'timestamp' => time()
                    ]
                );
                break;
        }

        return true;
    }

    public function handleElicitationRequest(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $sessionVersion = $this->protocolManager->getSessionVersion($sessionId);
        if (!$this->protocolManager->isFeatureSupported('elicitation', $sessionVersion)) {
            return $this->responseManager->storeErrorResponse($sessionId, -32601, 'Elicitation not supported in this protocol version', $id, $response);
        }

        $prompt = $params['message'] ?? '';
        $requestedSchema = $params['requestedSchema'] ?? null;

        $elicitationData = [
        'type' => 'elicitation',
        'prompt' => $prompt,
        'requestedSchema' => $requestedSchema,
        'requestId' => $id
        ];

        return $this->responseManager->storeSuccessResponse($sessionId, $elicitationData, $id, $response);
    }

    public function requestElicitation(
        string $sessionId,
        string $message,
        ?array $requestedSchema = null,
        array $context = [],
        array $options = []
    ): string {
        $this->requireClientCapability($sessionId, 'elicitation');

        $requestId = bin2hex(random_bytes(16));

        $params = ['message' => $message];

        if (isset($options['url']) && $this->protocolManager->isFeatureSupported('elicitation_url', $this->protocolManager->getSessionVersion($sessionId))) {
            $params['mode'] = 'url';
            $params['url'] = $options['url'];
        } else {
            $params['mode'] = 'form';
            $params['requestedSchema'] = $requestedSchema ?? ['type' => 'object', 'properties' => new \stdClass()];
        }

        $elicitationRequest = [
        'jsonrpc' => '2.0',
        'method' => 'elicitation/create',
        'id' => $requestId,
        'params' => $params
        ];

        $this->storage->storeMessage($sessionId, $elicitationRequest, $context);
        $this->rememberRequest($sessionId, $requestId, 'elicitation/create');

        return $requestId;
    }
}
