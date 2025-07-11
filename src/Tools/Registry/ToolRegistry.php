<?php

namespace Seolinkmap\Waasup\Tools\Registry;

use Seolinkmap\Waasup\Exception\MCPException;
use Seolinkmap\Waasup\Tools\ToolInterface;

/**
 * Tool registry for managing available tools
 */
class ToolRegistry
{
    private array $tools = [];
    private array $callables = [];

    /**
     * Register a tool instance
     */
    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Register a callable as a tool (simpler registration)
     */
    public function register(string $name, callable $handler, array $schema = []): void
    {
        $this->callables[$name] = [
            'handler' => $handler,
            'schema' => $this->normalizeSchema($schema),
            'name' => $name
        ];
    }

    /**
     * Execute a tool by name
     */
    public function execute(string $toolName, array $parameters, array $context = []): array
    {
        // Check tool instances first
        if (isset($this->tools[$toolName])) {
            return $this->tools[$toolName]->execute($parameters, $context);
        }

        // Check callable tools
        if (isset($this->callables[$toolName])) {
            $callable = $this->callables[$toolName];
            return ($callable['handler'])($parameters, $context);
        }

        throw new MCPException("Tool not found: {$toolName}", -32601);
    }

    /**
     * Get all registered tools for tools/list response
     */
    public function getToolsList(): array
    {
        $tools = [];

        // Add tool instances
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
                'annotations' => $tool->getAnnotations()
            ];
        }

        // Add callable tools
        foreach ($this->callables as $name => $callable) {
            $tools[] = [
                'name' => $name,
                'description' => $callable['schema']['description'] ?? "Tool: {$name}",
                'inputSchema' => $callable['schema']['inputSchema'] ?? ['type' => 'object'],
                'annotations' => $callable['schema']['annotations'] ?? [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => true,
                    'openWorldHint' => false
                ]
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Check if a tool exists
     */
    public function hasTool(string $toolName): bool
    {
        return isset($this->tools[$toolName]) || isset($this->callables[$toolName]);
    }

    /**
     * Get list of tool names
     */
    public function getToolNames(): array
    {
        return array_merge(
            array_keys($this->tools),
            array_keys($this->callables)
        );
    }

    /**
     * Normalize schema for callable tools
     */
    private function normalizeSchema(array $schema): array
    {
        return [
            'description' => $schema['description'] ?? '',
            'inputSchema' => $schema['inputSchema'] ?? ['type' => 'object'],
            'annotations' => $schema['annotations'] ?? [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false
            ]
        ];
    }
}
