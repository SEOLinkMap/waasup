<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Seolinkmap\Waasup\Content\AudioContentHandler;
use Seolinkmap\Waasup\Exception\ProtocolException;

class ContentProcessor
{
    private ProtocolManager $protocolManager;

    public function __construct(ProtocolManager $protocolManager)
    {
        $this->protocolManager = $protocolManager;
    }

    public function processContentWithAudio(array $content, string $protocolVersion): array
    {
        $processedContent = [];

        foreach ($content as $item) {
            if (!isset($item['type'])) {
                throw new ProtocolException('Content item missing type', -32602);
            }

            switch ($item['type']) {
                case 'text':
                    $processedContent[] = $this->withAnnotations(
                        [
                        'type' => 'text',
                        'text' => $item['text'] ?? ''
                        ],
                        $item
                    );
                    break;

                case 'image':
                    $processedContent[] = $this->withAnnotations(
                        [
                        'type' => 'image',
                        'data' => $item['data'] ?? '',
                        'mimeType' => $item['mimeType'] ?? 'image/jpeg'
                        ],
                        $item
                    );
                    break;

                case 'resource':
                    if (!isset($item['resource']['uri'])) {
                        throw new ProtocolException("Embedded resource content requires a 'resource' object carrying a 'uri'.", -32602);
                    }

                    $processedContent[] = $this->withAnnotations(
                        [
                        'type' => 'resource',
                        'resource' => $item['resource']
                        ],
                        $item
                    );
                    break;

                case 'resource_link':
                    if (!$this->protocolManager->isFeatureSupported('resource_links', $protocolVersion)) {
                        throw new ProtocolException("Resource link content is not supported in version {$protocolVersion}. Return an embedded 'resource' or 'text' block instead.", -32602);
                    }

                    if (!isset($item['uri'])) {
                        throw new ProtocolException("Resource link content requires a 'uri'.", -32602);
                    }

                    $processedContent[] = $item;
                    break;

                case 'audio':
                    if (!$this->protocolManager->isFeatureSupported('audio_content', $protocolVersion)) {
                        throw new ProtocolException("Audio content not supported in version {$protocolVersion}", -32602);
                    }

                    try {
                        $processedContent[] = AudioContentHandler::processAudioContent($item);
                    } catch (\Exception $e) {
                        throw new ProtocolException("Invalid audio content: " . $e->getMessage(), -32602);
                    }
                    break;

                default:
                    throw new ProtocolException("Unsupported content type: {$item['type']}. Use text, image, audio, resource or resource_link.", -32602);
            }
        }

        return $processedContent;
    }

    /**
     * Copy the optional annotations and _meta of a content block
     */
    private function withAnnotations(array $processed, array $item): array
    {
        if (isset($item['annotations'])) {
            $processed['annotations'] = $item['annotations'];
        }

        if (isset($item['_meta'])) {
            $processed['_meta'] = $item['_meta'];
        }

        return $processed;
    }
}
