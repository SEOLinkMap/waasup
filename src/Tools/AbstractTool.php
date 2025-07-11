<?php

namespace Seolinkmap\Waasup\Tools;

/**
 * Abstract base class for tools
 */
abstract class AbstractTool implements ToolInterface
{
    protected string $name;
    protected string $description;
    protected array $inputSchema;
    protected array $annotations;

    public function __construct(
        string $name,
        string $description,
        array $inputSchema = [],
        array $annotations = []
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
        $this->annotations = array_merge($this->getDefaultAnnotations(), $annotations);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->inputSchema['properties'] ?? [],
            'required' => $this->inputSchema['required'] ?? []
        ];
    }

    public function getAnnotations(): array
    {
        return $this->annotations;
    }

    protected function getDefaultAnnotations(): array
    {
        return [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false
        ];
    }

    /**
     * Validate parameters against schema
     */
    protected function validateParameters(array $parameters): void
    {
        $required = $this->inputSchema['required'] ?? [];

        foreach ($required as $requiredParam) {
            if (!isset($parameters[$requiredParam])) {
                throw new \InvalidArgumentException("Missing required parameter: {$requiredParam}");
            }
        }
    }

    /**
     * Subclasses must implement this
     */
    abstract public function execute(array $parameters, array $context = []): array;
}
