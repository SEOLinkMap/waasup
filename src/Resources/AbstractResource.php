<?php

namespace Seolinkmap\Waasup\Resources;

/**
 * Abstract base class for resources
 */
abstract class AbstractResource implements ResourceInterface
{
    protected string $uri;
    protected string $name;
    protected string $description;
    protected string $mimeType;

    public function __construct(
        string $uri,
        string $name,
        string $description,
        string $mimeType = 'text/plain'
    ) {
        $this->uri = $uri;
        $this->name = $name;
        $this->description = $description;
        $this->mimeType = $mimeType;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Subclasses must implement this
     */
    abstract public function read(array $context = []): array;
}
