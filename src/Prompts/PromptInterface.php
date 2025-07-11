<?php

namespace Seolinkmap\Waasup\Prompts;

/**
 * Interface for MCP prompts
 */
interface PromptInterface
{
    /**
     * Get the prompt's name
     */
    public function getName(): string;

    /**
     * Get the prompt's description
     */
    public function getDescription(): string;

    /**
     * Get the prompt's input schema for arguments
     */
    public function getInputSchema(): array;

    /**
     * Execute the prompt with given arguments and context
     */
    public function execute(array $arguments, array $context = []): array;
}
