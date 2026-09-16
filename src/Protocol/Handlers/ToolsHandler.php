<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class ToolsHandler
{
    private const RESERVED_META_KEYS = ['structured', 'resourceLinks'];

    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private ProtocolManager $protocolManager;
    private ContentProcessor $contentProcessor;
    private ResponseManager $responseManager;

    public function __construct(
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ProtocolManager $protocolManager,
        ContentProcessor $contentProcessor,
        ResponseManager $responseManager,
        ?ResourceRegistry $resourceRegistry = null
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->protocolManager = $protocolManager;
        $this->contentProcessor = $contentProcessor;
        $this->responseManager = $responseManager;
        $this->resourceRegistry = $resourceRegistry ?? new ResourceRegistry();
    }

    public function handleToolsList(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $sessionVersion = $this->protocolManager->getSessionVersion($sessionId);
        $tools = $this->toolRegistry->getToolsList($sessionVersion)['tools'];

        $page = $this->responseManager->paginate(
            $tools,
            'name',
            $params['cursor'] ?? null,
            $this->protocolManager->getPageSize()
        );

        if ($page === null) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                'Invalid cursor. Re-request tools/list without a cursor to start again.',
                $id,
                $response
            );
        }

        $result = ['tools' => $page['items']];

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

    public function handleToolsCall(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (empty($toolName)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'name' is required and must be a registered tool name. Call tools/list to see the available tools.",
                $id,
                $response
            );
        }

        if (!$this->toolRegistry->hasTool($toolName)) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Unknown tool: {$toolName}. Call tools/list to see the available tools.",
                $id,
                $response
            );
        }

        $sessionVersion = $this->protocolManager->getSessionVersion($sessionId);
        $tasksSupported = $this->protocolManager->isFeatureSupported('tasks', $sessionVersion);
        $asTask = $tasksSupported && isset($params['task']);
        $taskSupport = $this->toolRegistry->getTaskSupport($toolName);

        if ($tasksSupported && $asTask && $taskSupport === 'forbidden') {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32601,
                "Tool '{$toolName}' cannot be invoked as a task. Call it without the 'task' parameter.",
                $id,
                $response
            );
        }

        if ($tasksSupported && !$asTask && $taskSupport === 'required') {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32601,
                "Tool '{$toolName}' must be invoked as a task. Repeat the call with a 'task' parameter.",
                $id,
                $response
            );
        }

        try {
            $result = $this->toolRegistry->execute($toolName, $arguments, $context);
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $failure = [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Tool '{$toolName}' failed: " . $e->getMessage()
                    ]
                ],
                'isError' => true
            ];

            if ($asTask) {
                return $this->createTask($sessionId, $params['task'], $failure, $id, $response);
            }

            return $this->responseManager->storeSuccessResponse($sessionId, $failure, $id, $response);
        }

        try {
            $toolResult = $this->buildToolResult($result, $sessionVersion);
        } catch (ProtocolException $e) {
            return $this->responseManager->storeErrorResponse($sessionId, -32603, $e->getMessage(), $id, $response);
        }

        $outputSchema = $this->toolRegistry->getOutputSchema($toolName);

        if (!empty($outputSchema) && $this->protocolManager->isFeatureSupported('structured_outputs', $sessionVersion)) {
            $problem = $this->findSchemaViolation($outputSchema, $toolResult['structuredContent'] ?? null);

            if ($problem !== null) {
                return $this->responseManager->storeErrorResponse(
                    $sessionId,
                    -32603,
                    "Tool '{$toolName}' declares an outputSchema, so its structuredContent must match it: {$problem}",
                    $id,
                    $response
                );
            }
        }

        $inputRequests = $this->protocolManager->getSessionValue($sessionId, 'input_requests', []);

        if ($this->usesTaskExtension($sessionId, $context)) {
            return $this->createExtensionTask($sessionId, $toolResult, $inputRequests, $id, $response);
        }

        $interim = $this->responseManager->inputRequired($sessionId, $id, $response);

        if ($interim !== null) {
            return $interim;
        }

        if ($asTask) {
            return $this->createTask($sessionId, $params['task'], $toolResult, $id, $response);
        }

        return $this->responseManager->storeSuccessResponse($sessionId, $toolResult, $id, $response);
    }

    /**
     * Whether this call should answer with a task from the tasks extension
     */
    private function usesTaskExtension(string $sessionId, array $context): bool
    {
        $version = $this->protocolManager->getSessionVersion($sessionId);

        if (!$this->protocolManager->isFeatureSupported('tasks_extension', $version)) {
            return false;
        }

        $capabilities = $this->protocolManager->getSessionValue($sessionId, 'client_capabilities', []);

        return isset($capabilities['extensions']['io.modelcontextprotocol/tasks']);
    }

    /**
     * Answer with a durable task handle instead of the call result
     *
     * @param array $toolResult the CallToolResult the call produced
     * @param array $inputRequests input the call still needs, keyed by input request id
     */
    private function createExtensionTask(string $sessionId, array $toolResult, array $inputRequests, mixed $id, Response $response): Response
    {
        $status = 'completed';

        if ($inputRequests !== []) {
            $status = 'input_required';
        } elseif (($toolResult['isError'] ?? false) === true) {
            $status = 'failed';
        }

        $task = $this->protocolManager->createTaskRecord($status, null);

        if ($inputRequests !== []) {
            $task['inputRequests'] = $inputRequests;
        } elseif ($status === 'failed') {
            $task['error'] = [
                'code' => -32603,
                'message' => $toolResult['content'][0]['text'] ?? 'The tool call failed.'
            ];
        } else {
            $task['result'] = $toolResult;
        }

        $this->protocolManager->storeTask($sessionId, $task);

        unset($task['result'], $task['error'], $task['inputRequests']);

        $task['resultType'] = 'task';

        return $this->responseManager->storeSuccessResponse($sessionId, $task, $id, $response);
    }

    /**
     * Record the outcome of a task-augmented call and answer with a CreateTaskResult
     *
     * @param array $taskParams the 'task' object from the request, read for 'ttl'
     * @param array $toolResult the CallToolResult the call produced
     */
    private function createTask(string $sessionId, array $taskParams, array $toolResult, mixed $id, Response $response): Response
    {
        $task = $this->protocolManager->createTaskRecord(
            ($toolResult['isError'] ?? false) === true ? 'failed' : 'completed',
            $taskParams['ttl'] ?? null
        );

        if ($task['status'] === 'failed') {
            $task['statusMessage'] = $toolResult['content'][0]['text'] ?? 'The tool call failed.';
        }

        $task['result'] = $toolResult;

        $this->protocolManager->storeTask($sessionId, $task);

        unset($task['result']);

        return $this->responseManager->storeSuccessResponse($sessionId, ['task' => $task], $id, $response);
    }

    /**
     * Shape a tool return value into a CallToolResult
     *
     * @param array $result the value the tool returned
     * @return array the CallToolResult to send
     */
    private function buildToolResult(array $result, string $protocolVersion): array
    {
        $structuredSupported = $this->protocolManager->isFeatureSupported('structured_outputs', $protocolVersion);

        if (isset($result['content']) && is_array($result['content'])) {
            $toolResult = ['content' => $this->contentProcessor->processContentWithAudio($result['content'], $protocolVersion)];

            if ($structuredSupported && isset($result['structuredContent'])) {
                $toolResult['structuredContent'] = $result['structuredContent'];
            }
        } elseif ($structuredSupported && isset($result['_meta']['structured']) && $result['_meta']['structured'] === true) {
            $toolResult = [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result['data'] ?? null, JSON_PRETTY_PRINT)
                    ]
                ],
                'structuredContent' => $result['data'] ?? null
            ];
        } else {
            $toolResult = [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        }

        if ($this->protocolManager->isFeatureSupported('resource_links', $protocolVersion)) {
            foreach ($result['_meta']['resourceLinks'] ?? [] as $link) {
                if (is_array($link) && isset($link['uri'])) {
                    $toolResult['content'][] = array_replace_recursive(['type' => 'resource_link'], $link);
                }
            }
        }

        $meta = array_diff_key($result['_meta'] ?? [], array_flip(self::RESERVED_META_KEYS));

        if ($meta !== []) {
            $toolResult['_meta'] = $meta;
        }

        $toolResult['isError'] = ($result['isError'] ?? false) === true;

        return $toolResult;
    }

    /**
     * Check structured content against a tool's declared output schema
     *
     * @return string|null the first violation found, null when it conforms
     */
    private function findSchemaViolation(array $schema, mixed $content): ?string
    {
        if ($content === null) {
            return 'no structuredContent was returned';
        }

        if (($schema['type'] ?? 'object') === 'object' && !is_array($content)) {
            return 'structuredContent must be an object';
        }

        foreach ($schema['required'] ?? [] as $property) {
            if (!is_array($content) || !array_key_exists($property, $content)) {
                return "required property '{$property}' is missing";
            }
        }

        foreach ($schema['properties'] ?? [] as $property => $definition) {
            if (!is_array($content) || !array_key_exists($property, $content) || !isset($definition['type'])) {
                continue;
            }

            if (!$this->matchesJsonType($content[$property], $definition['type'])) {
                return "property '{$property}' must be of type {$definition['type']}";
            }
        }

        return null;
    }

    /**
     * Check a value against a JSON Schema primitive type name
     */
    private function matchesJsonType(mixed $value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'array':
                return is_array($value) && array_is_list($value);
            case 'object':
                return $value instanceof \stdClass
                    || (is_array($value) && ($value === [] || !array_is_list($value)));
            default:
                return true;
        }
    }

    public function handleCompletionsComplete(array $params, mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required. Send an initialize request first and reuse the returned Mcp-Session-Id header.', -32001);
        }

        $ref = $params['ref'] ?? null;
        $argument = $params['argument'] ?? [];

        if (!is_array($ref) || empty($ref['type'])) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'ref' is required and must be an object with a 'type' of ref/prompt, ref/resource or ref/tool.",
                $id,
                $response
            );
        }

        if (!is_array($argument) || !isset($argument['name'])) {
            return $this->responseManager->storeErrorResponse(
                $sessionId,
                -32602,
                "Invalid params: 'argument' is required and must be an object with 'name' and 'value' keys.",
                $id,
                $response
            );
        }

        $values = $this->generateCompletions($ref, (string)$argument['name'], (string)($argument['value'] ?? ''));

        $result = [
            'completion' => [
                'values' => array_slice($values, 0, 100),
                'total' => count($values),
                'hasMore' => count($values) > 100
            ]
        ];

        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }

    /**
     * Collect completion candidates for one argument
     *
     * @param string $typed the partial value entered so far
     * @return string[] matching values
     */
    private function generateCompletions(array $ref, string $argumentName, string $typed): array
    {
        $candidates = [];
        $name = $ref['name'] ?? '';

        if ($ref['type'] === 'ref/prompt' && $this->promptRegistry->hasPrompt($name)) {
            $candidates = $this->promptRegistry->getPromptSchema($name)['properties'][$argumentName]['enum'] ?? [];
        } elseif ($ref['type'] === 'ref/resource') {
            $candidates = $this->resourceRegistry->getTemplateSchema($ref['uri'] ?? '')['properties'][$argumentName]['enum'] ?? [];
        } elseif ($ref['type'] === 'ref/tool' && $this->toolRegistry->hasTool($name)) {
            foreach ($this->toolRegistry->getToolsList()['tools'] as $tool) {
                if ($tool['name'] === $name) {
                    $candidates = $tool['inputSchema']['properties'][$argumentName]['enum'] ?? [];
                }
            }
        }

        if ($typed === '') {
            return array_values($candidates);
        }

        return array_values(
            array_filter(
                $candidates,
                fn ($candidate) => stripos((string)$candidate, $typed) === 0
            )
        );
    }
}
