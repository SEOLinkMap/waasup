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
        $this->assertValidName($tool->getName());

        $this->tools[$tool->getName()] = $tool;
    }

    public function register(string $name, callable $handler, array $schema = []): void
    {
        $this->assertValidName($name);

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

        throw new MCPException("Tool not found: {$toolName}. Call tools/list to see the available tools.", -32602);
    }

    public function getToolsList(string $protocolVersion = '2025-06-18'): array
    {
        $tools = [];
        $supportsAnnotations = $this->supportsToolAnnotations($protocolVersion);
        $supportsOutputSchema = strcmp($protocolVersion, '2025-06-18') >= 0;
        $supportsTitle = strcmp($protocolVersion, '2025-06-18') >= 0;
        $supportsIcons = strcmp($protocolVersion, '2025-11-25') >= 0;
        $supportsTasks = strcmp($protocolVersion, '2025-11-25') >= 0;

        foreach ($this->tools as $tool) {
            $toolData = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema()
            ];

            if ($supportsTitle && $tool->getTitle() !== '') {
                $toolData['title'] = $tool->getTitle();
            }

            if ($supportsOutputSchema) {
                $outputSchema = $tool->getOutputSchema();
                if (!empty($outputSchema)) {
                    $toolData['outputSchema'] = $outputSchema;
                }
            }

            if ($supportsAnnotations) {
                $annotations = $tool->getAnnotations();
                if (!empty($annotations)) {
                    $toolData['annotations'] = $annotations;
                }
            }

            if ($supportsIcons) {
                $icons = $tool->getIcons();
                if (!empty($icons)) {
                    $toolData['icons'] = $icons;
                }
            }

            $tools[] = $toolData;
        }

        foreach ($this->callables as $name => $callable) {
            $toolData = [
                'name' => $name,
                'description' => $callable['schema']['description'],
                'inputSchema' => $callable['schema']['inputSchema']
            ];

            if ($supportsTitle && !empty($callable['schema']['title'])) {
                $toolData['title'] = $callable['schema']['title'];
            }

            if ($supportsOutputSchema && !empty($callable['schema']['outputSchema'])) {
                $toolData['outputSchema'] = $callable['schema']['outputSchema'];
            }

            if ($supportsIcons && !empty($callable['schema']['icons'])) {
                $toolData['icons'] = $callable['schema']['icons'];
            }

            if ($supportsTasks && !empty($callable['schema']['execution'])) {
                $toolData['execution'] = $callable['schema']['execution'];
            }

            if ($supportsAnnotations && !empty($callable['schema']['annotations'])) {
                $toolData['annotations'] = $callable['schema']['annotations'];
            }

            $tools[] = $toolData;
        }

        return ['tools' => $tools];
    }

    private function supportsToolAnnotations(string $version): bool
    {
        return strcmp($version, '2025-03-26') >= 0;
    }

    /**
     * Get the declared output schema for a tool
     *
     * @return array JSON schema, empty when the tool declares none
     */
    public function getOutputSchema(string $toolName): array
    {
        if (isset($this->tools[$toolName])) {
            return $this->tools[$toolName]->getOutputSchema();
        }

        if (isset($this->callables[$toolName])) {
            return $this->callables[$toolName]['schema']['outputSchema'];
        }

        return [];
    }

    /**
     * How a tool may be invoked as a task: forbidden, optional or required
     */
    public function getTaskSupport(string $toolName): string
    {
        if (isset($this->callables[$toolName])) {
            return $this->callables[$toolName]['schema']['execution']['taskSupport'] ?? 'forbidden';
        }

        return 'forbidden';
    }

    /**
     * Parameters a tool mirrors into Mcp-Param headers
     *
     * @return array header name mapped to the property path carrying its value
     */
    public function getHeaderParameters(string $toolName): array
    {
        $schema = isset($this->tools[$toolName])
            ? $this->tools[$toolName]->getInputSchema()
            : ($this->callables[$toolName]['schema']['inputSchema'] ?? []);

        return $this->collectHeaderParameters($schema['properties'] ?? [], []);
    }

    /**
     * Walk a schema's properties for x-mcp-header annotations
     *
     * Only chains of 'properties' keys are followed, as the transport requires.
     *
     * @param array $properties the properties at this level
     * @param array $path the property path reached so far
     * @return array header name mapped to property path
     */
    private function collectHeaderParameters(array $properties, array $path): array
    {
        $found = [];

        foreach ($properties as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $here = array_merge($path, [$name]);

            if (isset($definition['x-mcp-header']) && is_string($definition['x-mcp-header'])) {
                $found[$definition['x-mcp-header']] = $here;
            }

            if (isset($definition['properties']) && is_array($definition['properties'])) {
                $found = array_merge($found, $this->collectHeaderParameters($definition['properties'], $here));
            }
        }

        return $found;
    }

    public function hasTool(string $toolName): bool
    {
        return isset($this->tools[$toolName]) || isset($this->callables[$toolName]);
    }

    public function getToolNames(): array
    {
        return array_merge(
            array_keys($this->tools),
            array_keys($this->callables)
        );
    }

    /**
     * Reject a tool name clients cannot address
     *
     * @throws \InvalidArgumentException when the name breaks the naming rules
     */
    private function assertValidName(string $name): void
    {
        if ($name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException(
                "Tool name must be between 1 and 128 characters, '{$name}' is " . strlen($name) . '.'
            );
        }

        if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Tool name '{$name}' may only contain letters, digits, underscore, hyphen and dot."
            );
        }
    }

    private function normalizeSchema(array $schema): array
    {
        return [
            'title' => $schema['title'] ?? '',
            'description' => $schema['description'] ?? '',
            'inputSchema' => $schema['inputSchema'] ?? ['type' => 'object'],
            'outputSchema' => $schema['outputSchema'] ?? [],
            'icons' => $schema['icons'] ?? [],
            'execution' => $schema['execution'] ?? [],
            'annotations' => $schema['annotations'] ?? []
        ];
    }
}
