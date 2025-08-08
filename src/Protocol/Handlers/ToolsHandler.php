<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class ToolsHandler
{
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ProtocolManager $protocolManager;
    private ContentProcessor $contentProcessor;
    private ResponseManager $responseManager;

    public function __construct(
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ProtocolManager $protocolManager,
        ContentProcessor $contentProcessor,
        ResponseManager $responseManager
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->protocolManager = $protocolManager;
        $this->contentProcessor = $contentProcessor;
        $this->responseManager = $responseManager;
    }

    public function handleToolsList(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionVersion = $this->protocolManager->getSessionVersion($sessionId);
        $result = $this->toolRegistry->getToolsList($sessionVersion);

        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    public function handleToolsCall(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, 'Invalid params: missing tool name', $id, $response);
        }

        try {
            $result = $this->toolRegistry->execute($toolName, $arguments, $context);

            $sessionVersion = $this->protocolManager->getSessionVersion($sessionId);

            if (isset($result['content']) && is_array($result['content'])) {
                $result['content'] = $this->contentProcessor->processContentWithAudio($result['content'], $sessionVersion);
            }

            // Structured outputs only in 2025-06-18
            if ($this->protocolManager->isFeatureSupported('structured_outputs', $sessionVersion)) {
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
                    if ($this->protocolManager->isFeatureSupported('resource_links', $sessionVersion)) {
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

            return $this->responseManager->storeSuccessResponse($sessionId, $wrappedResult, $id, $response);
        } catch (\Exception $e) {
            $errorResult = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Tool execution failed'
                ]
            ]
            ];

            return $this->responseManager->storeSuccessResponse($sessionId, $errorResult, $id, $response);
        }
    }

    public function handleCompletionsComplete(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $ref = $params['ref'] ?? null;
        $argument = $params['argument'] ?? null;

        if (!$ref) {
            return $this->responseManager->storeErrorResponse($sessionId, -32602, 'Invalid params: missing ref', $id, $response);
        }

        try {
            $completions = $this->generateCompletions($ref, $argument, $context);
            $result = ['completions' => $completions];
            return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
        } catch (\Exception $e) {
            return $this->responseManager->storeErrorResponse($sessionId, -32603, 'Completion generation failed', $id, $response);
        }
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
}
