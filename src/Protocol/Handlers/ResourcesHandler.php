<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;

class ResourcesHandler
{
    private ResourceRegistry $resourceRegistry;
    private ResponseManager $responseManager;

    public function __construct(
        ResourceRegistry $resourceRegistry,
        ResponseManager $responseManager
    ) {
        $this->resourceRegistry = $resourceRegistry;
        $this->responseManager = $responseManager;
    }

    public function handleResourcesList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->resourceRegistry->getResourcesList();
        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    public function handleResourcesRead(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $uri = $params['uri'] ?? '';

        if (empty($uri)) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, 'Invalid params: missing resource uri', $id, $response);
        }

        try {
            $result = $this->resourceRegistry->read($uri, $context);

            $wrappedResult = [
                'contents' => $result['contents'] ?? []
            ];

            return $this->responseManager->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
        } catch (\Exception $e) {
            $errorResult = [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/plain',
                        'text' => 'Resource read failed'
                    ]
                ]
            ];

            return $this->responseManager->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }

    public function handleResourceTemplatesList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->resourceRegistry->getResourceTemplatesList();
        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }
}
