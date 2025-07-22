<?php

namespace Seolinkmap\Waasup\Content;

/**
 * Audio content handler for MCP 2025-03-26+
 */
class AudioContentHandler
{
    private const SUPPORTED_AUDIO_TYPES = [
        'audio/mpeg',     // MP3
        'audio/wav',      // WAV
        'audio/ogg',      // OGG
        'audio/mp4',      // M4A
        'audio/webm',     // WebM Audio
        'audio/flac',     // FLAC
        'audio/aac',      // AAC
    ];

    private const MAX_AUDIO_SIZE = 50 * 1024 * 1024; // 50MB max

    /**
     * Validate and process audio content
     */
    public static function processAudioContent(array $content): array
    {
        if ($content['type'] !== 'audio') {
            throw new \InvalidArgumentException('Content type must be audio');
        }

        // Required fields for audio content
        $requiredFields = ['data', 'mimeType'];
        foreach ($requiredFields as $field) {
            if (!isset($content[$field])) {
                throw new \InvalidArgumentException("Missing required audio field: {$field}");
            }
        }

        $mimeType = $content['mimeType'];
        $audioData = $content['data'];

        // Validate MIME type
        if (!in_array($mimeType, self::SUPPORTED_AUDIO_TYPES)) {
            throw new \InvalidArgumentException("Unsupported audio MIME type: {$mimeType}");
        }

        // Validate base64 data
        if (!self::isValidBase64($audioData)) {
            throw new \InvalidArgumentException('Audio data must be valid base64');
        }

        // Check size limits
        $decoded = base64_decode($audioData, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64 audio data');
        }

        $decodedSize = strlen($decoded);
        if ($decodedSize > self::MAX_AUDIO_SIZE) {
            throw new \InvalidArgumentException('Audio file too large (max 50MB)');
        }

        // Return normalized audio content
        return [
            'type' => 'audio',
            'mimeType' => $mimeType,
            'data' => $audioData,
            'size' => $decodedSize,
            'duration' => $content['duration'] ?? null, // Optional duration in seconds
            'name' => $content['name'] ?? null,         // Optional filename
            'annotations' => $content['annotations'] ?? [] // Optional metadata
        ];
    }

    /**
     * Create audio content from file
     */
    public static function createFromFile(string $filePath, ?string $name = null): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Audio file not found: {$filePath}");
        }

        $mimeType = self::detectMimeType($filePath);
        if (!$mimeType) {
            throw new \InvalidArgumentException("Cannot determine MIME type for: {$filePath}");
        }

        // Handle file_get_contents returning false
        $audioData = file_get_contents($filePath);
        if ($audioData === false) {
            throw new \InvalidArgumentException("Cannot read audio file: {$filePath}");
        }

        if (strlen($audioData) > self::MAX_AUDIO_SIZE) {
            throw new \InvalidArgumentException('Audio file too large (max 50MB)');
        }

        return [
            'type' => 'audio',
            'mimeType' => $mimeType,
            'data' => base64_encode($audioData), // Now $audioData is guaranteed to be string
            'size' => strlen($audioData),
            'name' => $name ?? basename($filePath)
        ];
    }

    /**
     * Extract audio content to temporary file
     */
    public static function extractToFile(array $audioContent, ?string $outputPath = null): string
    {
        if ($audioContent['type'] !== 'audio') {
            throw new \InvalidArgumentException('Content is not audio type');
        }

        $audioData = base64_decode($audioContent['data'], true);
        if ($audioData === false) {
            throw new \InvalidArgumentException('Invalid base64 audio data');
        }

        if ($outputPath === null) {
            $extension = self::getExtensionFromMimeType($audioContent['mimeType']);
            $outputPath = tempnam(sys_get_temp_dir(), 'mcp_audio_') . '.' . $extension;
        }

        if (file_put_contents($outputPath, $audioData) === false) {
            throw new \RuntimeException("Cannot write audio file to: {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Validate base64 string
     */
    private static function isValidBase64(string $data): bool
    {
        $decoded = base64_decode($data, true);
        return $decoded !== false && base64_encode($decoded) === $data;
    }

    /**
     * Detect MIME type from file
     */
    private static function detectMimeType(string $filePath): ?string
    {
        // Handle finfo_open returning false
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                // Fall through to extension-based detection
            } else {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);

                if ($mimeType !== false && in_array($mimeType, self::SUPPORTED_AUDIO_TYPES)) {
                    return $mimeType;
                }
            }
        }

        // Fallback to extension-based detection
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            default => null
        };
    }

    /**
     * Get file extension from MIME type
     */
    private static function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'audio/webm' => 'webm',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',
            default => 'bin'
        };
    }

    /**
     * Get supported audio MIME types
     */
    public static function getSupportedMimeTypes(): array
    {
        return self::SUPPORTED_AUDIO_TYPES;
    }

    /**
     * Check if MIME type is supported
     */
    public static function isSupportedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_AUDIO_TYPES);
    }
}
