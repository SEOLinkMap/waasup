<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;

class PromptsHandler
{
    private PromptRegistry $promptRegistry;
    private ResponseManager $responseManager;

    public function __construct(
        PromptRegistry $promptRegistry,
        ResponseManager $responseManager
    ) {
        $this->promptRegistry = $promptRegistry;
        $this->responseManager = $responseManager;
    }

    public function handlePromptsList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = $this->promptRegistry->getPromptsList();
        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    public function handlePromptsGet(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $promptName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($promptName)) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, 'Invalid params: missing prompt name', $id, $response);
        }

        try {
            $result = $this->promptRegistry->execute($promptName, $arguments, $context);

            $wrappedResult = [
                'description' => $result['description'] ?? '',
                'messages' => $result['messages'] ?? []
            ];

            return $this->responseManager->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
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

            return $this->responseManager->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }
}
