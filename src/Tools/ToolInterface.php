<?php

namespace Seolinkmap\Waasup\Tools;

/**
 * Interface for MCP tools
 */
interface ToolInterface
{
    /**
     * Get the tool's name
     */
    public function getName(): string;

    /**
     * Get the tool's description
     */
    public function getDescription(): string;

    /**
     * Get the tool's input schema
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with given parameters and context
     */
    public function execute(array $parameters, array $context = []): array;

    /**
     * Get tool annotations (readOnlyHint, destructiveHint, etc.)
     */
    public function getAnnotations(): array;
}
