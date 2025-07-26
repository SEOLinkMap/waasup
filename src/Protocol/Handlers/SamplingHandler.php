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

    public function handleSamplingResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
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

    public function handleRootsListResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    public function handleRootsReadResponse(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
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

        return $this->responseManager->storeSuccessResponse($sessionId, ['received' => true], $id, $response);
    }

    // Elicitation only supported in 2025-06-18
    public function handleElicitationRequest(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
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
}
