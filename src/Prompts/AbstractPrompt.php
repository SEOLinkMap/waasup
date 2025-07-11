<?php

namespace Seolinkmap\Waasup\Prompts;

/**
 * Abstract base class for prompts
 */
abstract class AbstractPrompt implements PromptInterface
{
    protected string $name;
    protected string $description;
    protected array $inputSchema;

    public function __construct(
        string $name,
        string $description,
        array $inputSchema = []
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
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

    /**
     * Validate arguments against schema
     */
    protected function validateArguments(array $arguments): void
    {
        $required = $this->inputSchema['required'] ?? [];

        foreach ($required as $requiredArg) {
            if (!isset($arguments[$requiredArg])) {
                throw new \InvalidArgumentException("Missing required argument: {$requiredArg}");
            }
        }
    }

    /**
     * Subclasses must implement this
     */
    abstract public function execute(array $arguments, array $context = []): array;
}
