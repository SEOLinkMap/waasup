<?php

namespace Seolinkmap\Waasup\Tools\Registry;

use Seolinkmap\Waasup\Exception\MCPException;
use Seolinkmap\Waasup\Tools\ToolInterface;

class ToolRegistry
{
    private array $tools = [];
    private array $callables = [];

    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function register(string $name, callable $handler, array $schema = []): void
    {
        $this->callables[$name] = [
            'handler' => $handler,
            'schema' => $this->normalizeSchema($schema),
            'name' => $name
        ];
    }

    public function execute(string $toolName, array $parameters, array $context = []): array
    {
        if (isset($this->tools[$toolName])) {
            return $this->tools[$toolName]->execute($parameters, $context);
        }

        if (isset($this->callables[$toolName])) {
            $callable = $this->callables[$toolName];
            return ($callable['handler'])($parameters, $context);
        }

        throw new MCPException("Tool not found: {$toolName}", -32601);
    }

    public function getToolsList(string $protocolVersion = '2025-06-18'): array
    {
        $tools = [];
        $supportsAnnotations = $this->supportsToolAnnotations($protocolVersion);
        $supportsOutputSchema = ($protocolVersion === '2025-06-18');

        foreach ($this->tools as $tool) {
            $toolData = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema()
            ];

            // Add outputSchema for 2025-06-18
            if ($supportsOutputSchema) {
                $outputSchema = $tool->getOutputSchema();
                if (!empty($outputSchema)) {
                    $toolData['outputSchema'] = $outputSchema;
                }
            }

            // Tool annotations only in 2025-03-26+
            if ($supportsAnnotations) {
                $toolData['annotations'] = $tool->getAnnotations();
            }

            $tools[] = $toolData;
        }

        foreach ($this->callables as $name => $callable) {
            $toolData = [
                'name' => $name,
                'description' => $callable['schema']['description'],
                'inputSchema' => $callable['schema']['inputSchema']
            ];

            // Add outputSchema for 2025-06-18
            if ($supportsOutputSchema && !empty($callable['schema']['outputSchema'])) {
                $toolData['outputSchema'] = $callable['schema']['outputSchema'];
            }

            if ($supportsAnnotations) {
                $toolData['annotations'] = $callable['schema']['annotations'] ?? [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => true,
                    'openWorldHint' => false
                ];
            }

            $tools[] = $toolData;
        }

        return ['tools' => $tools];
    }

    private function supportsToolAnnotations(string $version): bool
    {
        return in_array($version, ['2025-03-26', '2025-06-18']);
    }

    public function hasTool(string $toolName): bool
    {
        return isset($this->tools[$toolName]) || isset($this->callables[$toolName]);
    }

    public function getToolNames(): array
    {
        return array_replace_recursive(
            array_keys($this->tools),
            array_keys($this->callables)
        );
    }

    private function normalizeSchema(array $schema): array
    {
        return [
            'description' => $schema['description'] ?? '',
            'inputSchema' => $schema['inputSchema'] ?? ['type' => 'object'],
            'outputSchema' => $schema['outputSchema'] ?? [],
            'annotations' => $schema['annotations'] ?? [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false
            ]
        ];
    }
}
