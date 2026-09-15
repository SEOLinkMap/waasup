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

        if (isset($this->prompts[$promptName])) {
            return $this->prompts[$promptName]->execute($arguments, $context);
        }

        if (isset($this->callables[$promptName])) {
            $callable = $this->callables[$promptName];
            return ($callable['handler'])($arguments, $context);
        }

        throw new MCPException("Prompt not found: {$promptName}. Call prompts/list to see the available prompts.", -32602);
    }

    /**
     * Get all registered prompts for prompts/list response
     */
    public function getPromptsList(string $protocolVersion = '2025-11-25'): array
    {
        $prompts = [];
        $supportsIcons = strcmp($protocolVersion, '2025-11-25') >= 0;
        $supportsTitle = strcmp($protocolVersion, '2025-06-18') >= 0;

        foreach ($this->prompts as $prompt) {
            $promptData = [
                'name' => $prompt->getName(),
                'description' => $prompt->getDescription(),
                'arguments' => $this->convertSchemaToArguments($prompt->getInputSchema())
            ];

            if ($supportsTitle && $prompt->getTitle() !== '') {
                $promptData['title'] = $prompt->getTitle();
            }

            if ($supportsIcons && !empty($prompt->getIcons())) {
                $promptData['icons'] = $prompt->getIcons();
            }

            $prompts[] = $promptData;
        }

        foreach ($this->callables as $name => $callable) {
            $promptData = [
                'name' => $name,
                'description' => $callable['schema']['description'] ?? "Prompt: {$name}",
                'arguments' => $this->convertSchemaToArguments($callable['schema']['inputSchema'] ?? ['type' => 'object'])
            ];

            if ($supportsTitle && !empty($callable['schema']['title'])) {
                $promptData['title'] = $callable['schema']['title'];
            }

            if ($supportsIcons && !empty($callable['schema']['icons'])) {
                $promptData['icons'] = $callable['schema']['icons'];
            }

            $prompts[] = $promptData;
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
     * Get the declared input schema for a prompt
     *
     * @return array JSON schema, empty when the prompt is not registered
     */
    public function getPromptSchema(string $promptName): array
    {
        if (isset($this->prompts[$promptName])) {
            return $this->prompts[$promptName]->getInputSchema();
        }

        if (isset($this->callables[$promptName])) {
            return $this->callables[$promptName]['schema']['inputSchema'];
        }

        return [];
    }

    /**
     * Get list of prompt names
     */
    public function getPromptNames(): array
    {
        return array_merge(
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
            'title' => $schema['title'] ?? '',
            'description' => $schema['description'] ?? '',
            'inputSchema' => $schema['inputSchema'] ?? ['type' => 'object'],
            'icons' => $schema['icons'] ?? []
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
