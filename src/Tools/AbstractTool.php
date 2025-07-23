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
        'openWorldHint' => false,
        'experimental' => false,
        'requiresUserConfirmation' => false,
        'sensitive' => false
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
