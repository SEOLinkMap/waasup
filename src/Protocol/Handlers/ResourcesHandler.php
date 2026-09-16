<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;

class ResourcesHandler
{
    private ResourceRegistry $resourceRegistry;
    private ResponseManager $responseManager;
    private ProtocolManager $protocolManager;

    public function __construct(
        ResourceRegistry $resourceRegistry,
        ResponseManager $responseManager,
        ProtocolManager $protocolManager
    ) {
        $this->resourceRegistry = $resourceRegistry;
        $this->responseManager = $responseManager;
        $this->protocolManager = $protocolManager;
    }

    public function handleResourcesList(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $resources = $this->resourceRegistry->getResourcesList($this->protocolManager->getSessionVersion($sessionId))['resources'];

        return $this->respondWithPage($sessionId, 'resources', $resources, 'uri', $params, $id, $response);
    }

    public function handleResourcesRead(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $uri = $params['uri'] ?? '';

        if (empty($uri)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'uri' is required. Call resources/list to see the available resources.",
                $id,
                $response
            );
        }

        if (!$this->resourceRegistry->hasResource($uri)) {
            $notFound = $this->protocolManager->isFeatureSupported(
                'stateless',
                $this->protocolManager->getSessionVersion($sessionId)
            ) ? -32602 : -32002;

            return $this->responseManager->storeErrorResponse(
                $sessionId,
                $notFound,
                "Resource not found: {$uri}. Call resources/list or resources/templates/list to see what this server exposes.",
                $id,
                $response
            );
        }

        try {
            $result = $this->resourceRegistry->read($uri, $context);
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32603,
                "Reading resource {$uri} failed: " . $e->getMessage(),
                $id,
                $response
            );
        }

        $interim = $this->responseManager->inputRequired($sessionId, $id, $response);

        if ($interim !== null) {
            return $interim;
        }

        $wrappedResult = $this->responseManager->cacheable(
            ['contents' => $result['contents'] ?? []],
            $sessionId
        );

        return $this->responseManager->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
    }

    /**
     * Subscribe the session to a resource URI
     */
    public function handleResourcesSubscribe(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        return $this->changeSubscription($params, $id, $sessionId, $response, true);
    }

    /**
     * Unsubscribe the session from a resource URI
     */
    public function handleResourcesUnsubscribe(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        return $this->changeSubscription($params, $id, $sessionId, $response, false);
    }

    /**
     * Add or remove a resource URI from the session's subscription list
     *
     * @param bool $subscribe true to add, false to remove
     */
    private function changeSubscription(array $params, mixed $id, ?string $sessionId, Response $response, bool $subscribe): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $uri = $params['uri'] ?? '';

        if (empty($uri)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'uri' is required. Call resources/list to see the available resources.",
                $id,
                $response
            );
        }

        $subscriptions = $this->protocolManager->getSessionValue($sessionId, 'resource_subscriptions', []);

        if ($subscribe) {
            $subscriptions[] = $uri;
            $subscriptions = array_values(array_unique($subscriptions));
        } else {
            $subscriptions = array_values(array_diff($subscriptions, [$uri]));
        }

        $this->protocolManager->storeSessionValue($sessionId, 'resource_subscriptions', $subscriptions);

        return $this->responseManager->storeSuccessResponse($sessionId, new \stdClass(), $id, $response);
    }

    public function handleResourceTemplatesList(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $templates = $this->resourceRegistry->getResourceTemplatesList($this->protocolManager->getSessionVersion($sessionId))['resourceTemplates'];

        return $this->respondWithPage($sessionId, 'resourceTemplates', $templates, 'uriTemplate', $params, $id, $response);
    }

    /**
     * Send one page of a resource list
     *
     * @param string $resultKey key the page is returned under
     * @param array $items the full list
     * @param string $keyField field identifying an item
     * @param array $params request params, read for 'cursor'
     */
    private function respondWithPage(
        string $sessionId,
        string $resultKey,
        array $items,
        string $keyField,
        array $params,
        mixed $id,
        Response $response
    ): Response {
        $page = $this->responseManager->paginate(
            $items,
            $keyField,
            $params['cursor'] ?? null,
            $this->protocolManager->getPageSize()
        );

        if ($page === null) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                'Invalid cursor. Re-request the list without a cursor to start again.',
                $id,
                $response
            );
        }

        $result = [$resultKey => $page['items']];

        if ($page['nextCursor'] !== null) {
            $result['nextCursor'] = $page['nextCursor'];
        }

        return $this->responseManager->storeSuccessResponse(
            $sessionId,
            $this->responseManager->cacheable($result, $sessionId),
            $id,
            $response
        );
    }
}
