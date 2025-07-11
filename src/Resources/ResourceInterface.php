<?php

namespace Seolinkmap\Waasup\Resources;

/**
 * Interface for MCP resources
 */
interface ResourceInterface
{
    /**
     * Get the resource's URI
     */
    public function getUri(): string;

    /**
     * Get the resource's name
     */
    public function getName(): string;

    /**
     * Get the resource's description
     */
    public function getDescription(): string;

    /**
     * Get the resource's MIME type
     */
    public function getMimeType(): string;

    /**
     * Read the resource content
     */
    public function read(array $context = []): array;
}
