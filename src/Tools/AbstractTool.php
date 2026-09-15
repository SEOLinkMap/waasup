<?php

namespace Seolinkmap\Waasup\Tools;

use Seolinkmap\Waasup\Content\AudioContentHandler;

/**
 * Abstract base class for tools
 */
abstract class AbstractTool implements ToolInterface
{
    protected string $name;
    protected string $description;
    protected array $inputSchema;
    protected array $outputSchema;
    protected array $annotations;
    protected array $icons;
    protected string $title;

    public function __construct(
        string $name,
        string $description,
        array $inputSchema = [],
        array $annotations = [],
        array $outputSchema = [],
        array $icons = [],
        string $title = ''
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
        $this->outputSchema = $outputSchema;
        $this->annotations = array_replace_recursive($this->getDefaultAnnotations(), $annotations);
        $this->icons = $icons;
        $this->title = $title;
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
        $properties = $this->inputSchema['properties'] ?? [];
        $required = $this->inputSchema['required'] ?? [];

        if (empty($properties)) {
            return ['type' => 'object', 'additionalProperties' => false];
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if (!empty($required)) {
            $schema['required'] = array_values($required);
        }

        return $schema;
    }

    public function getOutputSchema(): array
    {
        return $this->outputSchema;
    }

    public function getAnnotations(): array
    {
        return $this->annotations;
    }

    public function getIcons(): array
    {
        return $this->icons;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Behaviour hints a tool declares about itself
     */
    protected function getDefaultAnnotations(): array
    {
        return [];
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
     * Create audio content response
     */
    protected function createAudioResponse(string $filePath, string $text = '', ?string $name = null): array
    {
        $audioContent = AudioContentHandler::createFromFile($filePath, $name);

        $content = [];

        if (!empty($text)) {
            $content[] = [
                'type' => 'text',
                'text' => $text
            ];
        }

        $content[] = $audioContent;

        return ['content' => $content];
    }

    /**
     * Create mixed content response with audio
     */
    protected function createMixedContentResponse(array $items): array
    {
        $content = [];

        foreach ($items as $item) {
            if ($item['type'] === 'audio_file') {
                $content[] = AudioContentHandler::createFromFile($item['path'], $item['name'] ?? null);
            } elseif ($item['type'] === 'audio_data') {
                $content[] = AudioContentHandler::processAudioContent($item);
            } else {
                $content[] = $item;
            }
        }

        return ['content' => $content];
    }

    /**
     * Validate audio input parameter
     */
    protected function validateAudioParameter(array $parameters, string $paramName): void
    {
        if (isset($parameters[$paramName])) {
            try {
                AudioContentHandler::processAudioContent($parameters[$paramName]);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Invalid audio parameter '{$paramName}': " . $e->getMessage());
            }
        }
    }

    /**
     * Subclasses must implement this
     */
    abstract public function execute(array $parameters, array $context = []): array;
}
