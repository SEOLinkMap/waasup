<?php

namespace Seolinkmap\Waasup\Prompts\Registry;

use Seolinkmap\Waasup\Exception\MCPException;
use Seolinkmap\Waasup\Prompts\PromptInterface;

/**
 * Prompt registry for managing available prompts
 */
class PromptRegistry
{
    private array $prompts = [];
    private array $callables = [];

    /**
     * Register a prompt instance
     */
    public function registerPrompt(PromptInterface $prompt): void
    {
        $this->prompts[$prompt->getName()] = $prompt;
    }

    /**
     * Register a callable as a prompt (simpler registration)
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
     * Execute a prompt by name
     */
    public function execute(string $promptName, array $arguments, array $context = []): array
    {
        // Check prompt instances first
        if (isset($this->prompts[$promptName])) {
            return $this->prompts[$promptName]->execute($arguments, $context);
        }

        // Check callable prompts
        if (isset($this->callables[$promptName])) {
            $callable = $this->callables[$promptName];
            return ($callable['handler'])($arguments, $context);
        }

        throw new MCPException("Prompt not found: {$promptName}", -32601);
    }

    /**
     * Get all registered prompts for prompts/list response
     */
    public function getPromptsList(): array
    {
        $prompts = [];

        // Add prompt instances
        foreach ($this->prompts as $prompt) {
            $prompts[] = [
                'name' => $prompt->getName(),
                'description' => $prompt->getDescription(),
                'arguments' => $this->convertSchemaToArguments($prompt->getInputSchema())
            ];
        }

        // Add callable prompts
        foreach ($this->callables as $name => $callable) {
            $prompts[] = [
                'name' => $name,
                'description' => $callable['schema']['description'] ?? "Prompt: {$name}",
                'arguments' => $this->convertSchemaToArguments($callable['schema']['inputSchema'] ?? ['type' => 'object'])
            ];
        }

        return ['prompts' => $prompts];
    }

    /**
     * Check if a prompt exists
     */
    public function hasPrompt(string $promptName): bool
    {
        return isset($this->prompts[$promptName]) || isset($this->callables[$promptName]);
    }

    /**
     * Get list of prompt names
     */
    public function getPromptNames(): array
    {
        return array_replace_recursive(
            array_keys($this->prompts),
            array_keys($this->callables)
        );
    }

    /**
     * Normalize schema for callable prompts
     */
    private function normalizeSchema(array $schema): array
    {
        return [
            'description' => $schema['description'] ?? '',
            'inputSchema' => $schema['inputSchema'] ?? ['type' => 'object']
        ];
    }

    /**
     * Convert JSON schema to MCP arguments format
     */
    private function convertSchemaToArguments(array $schema): array
    {
        $arguments = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $property) {
            $argument = [
                'name' => $name,
                'description' => $property['description'] ?? '',
                'required' => in_array($name, $required)
            ];

            if (isset($property['type'])) {
                $argument['type'] = $property['type'];
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }
}
