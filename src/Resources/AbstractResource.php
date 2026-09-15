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
    protected array $icons;
    protected string $title;

    public function __construct(
        string $uri,
        string $name,
        string $description,
        string $mimeType = 'text/plain',
        array $icons = [],
        string $title = ''
    ) {
        $this->uri = $uri;
        $this->name = $name;
        $this->description = $description;
        $this->mimeType = $mimeType;
        $this->icons = $icons;
        $this->title = $title;
    }

    public function getIcons(): array
    {
        return $this->icons;
    }

    public function getTitle(): string
    {
        return $this->title;
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
