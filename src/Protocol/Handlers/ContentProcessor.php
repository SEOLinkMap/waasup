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

    // Audio content processing only for 2025-03-26+
    public function processContentWithAudio(array $content, string $protocolVersion): array
    {
        $processedContent = [];

        foreach ($content as $item) {
            if (!isset($item['type'])) {
                throw new ProtocolException('Content item missing type', -32602);
            }

            switch ($item['type']) {
                case 'text':
                    $processedContent[] = [
                        'type' => 'text',
                        'text' => $item['text'] ?? ''
                    ];
                    break;

                case 'image':
                    $processedContent[] = [
                        'type' => 'image',
                        'data' => $item['data'] ?? '',
                        'mimeType' => $item['mimeType'] ?? 'image/jpeg'
                    ];
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
                    throw new ProtocolException("Unsupported content type: {$item['type']}", -32602);
            }
        }

        return $processedContent;
    }
}
