<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;

class PromptsHandler
{
    private PromptRegistry $promptRegistry;
    private ResponseManager $responseManager;
    private ProtocolManager $protocolManager;

    public function __construct(
        PromptRegistry $promptRegistry,
        ResponseManager $responseManager,
        ProtocolManager $protocolManager
    ) {
        $this->promptRegistry = $promptRegistry;
        $this->responseManager = $responseManager;
        $this->protocolManager = $protocolManager;
    }

    public function handlePromptsList(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $prompts = $this->promptRegistry->getPromptsList($this->protocolManager->getSessionVersion($sessionId))['prompts'];

        $page = $this->responseManager->paginate(
            $prompts,
            'name',
            $params['cursor'] ?? null,
            $this->protocolManager->getPageSize()
        );

        if ($page === null) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                'Invalid cursor. Re-request prompts/list without a cursor to start again.',
                $id,
                $response
            );
        }

        $result = ['prompts' => $page['items']];

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

    public function handlePromptsGet(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $promptName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($promptName)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'name' is required. Call prompts/list to see the available prompts.",
                $id,
                $response
            );
        }

        if (!$this->promptRegistry->hasPrompt($promptName)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Unknown prompt: {$promptName}. Call prompts/list to see the available prompts.",
                $id,
                $response
            );
        }

        try {
            $result = $this->promptRegistry->execute($promptName, $arguments, $context);
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32603,
                "Prompt '{$promptName}' failed: " . $e->getMessage(),
                $id,
                $response
            );
        }

        $interim = $this->responseManager->inputRequired($sessionId, $id, $response);

        if ($interim !== null) {
            return $interim;
        }

        $wrappedResult = [
            'description' => $result['description'] ?? '',
            'messages' => $result['messages'] ?? []
        ];

        return $this->responseManager->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
    }
}
