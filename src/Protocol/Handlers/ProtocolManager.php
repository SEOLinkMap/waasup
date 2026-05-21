<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Storage\StorageInterface;

class ProtocolManager
{
    private StorageInterface $storage;
    private array $config;

    // MCP spec feature matrix - gates features by protocol version
    private const FEATURE_MATRIX = [
        '2024-11-05' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => false,
            'audio_content' => false,
            'completions' => false,
            'elicitation' => false,
            'structured_outputs' => false,
            'resource_links' => false,
            'progress_messages' => false,
            'json_rpc_batching' => false,
            'oauth_resource_server' => false,
            'resource_indicators' => false
        ],
        '2025-03-26' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => false,
            'structured_outputs' => false,
            'resource_links' => false,
            'progress_messages' => true,
            'json_rpc_batching' => true,
            'oauth_resource_server' => false,
            'resource_indicators' => false
        ],
        '2025-06-18' => [
            'tools' => true,
            'prompts' => true,
            'resources' => true,
            'sampling' => true,
            'roots' => true,
            'ping' => true,
            'progress_notifications' => true,
            'tool_annotations' => true,
            'audio_content' => true,
            'completions' => true,
            'elicitation' => true,
            'structured_outputs' => true,
            'resource_links' => true,
            'progress_messages' => true,
            'json_rpc_batching' => false,
            'oauth_resource_server' => true,
            'resource_indicators' => true
        ]
    ];

    public function __construct(
        StorageInterface $storage,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->config = $config;
    }

    public function isMethodSupported(string $method, string $protocolVersion): bool
    {
        $methodFeatureMap = [
            'initialize' => 'tools',
            'ping' => 'ping',
            'tools/list' => 'tools',
            'tools/call' => 'tools',
            'prompts/list' => 'prompts',
            'prompts/get' => 'prompts',
            'resources/list' => 'resources',
            'resources/read' => 'resources',
            'resources/templates/list' => 'resources',
            'completions/complete' => 'completions',
            'elicitation/create' => 'elicitation',
            'sampling/createMessage' => 'sampling',
            'roots/list' => 'roots',
            'roots/read' => 'roots',
            'roots/listDirectory' => 'roots',
            'notifications/initialized' => 'tools',
            'notifications/cancelled' => 'tools',
            'notifications/progress' => 'progress_notifications'
        ];

        $feature = $methodFeatureMap[$method] ?? null;
        if (!$feature) {
            return false;
        }

        return $this->isFeatureSupported($feature, $protocolVersion);
    }

    public function isFeatureSupported(string $feature, string $protocolVersion): bool
    {
        return self::FEATURE_MATRIX[$protocolVersion][$feature] ?? false;
    }

    /**
     * Determine whether a JSON-RPC request response is returned directly on the
     * originating POST.
     *
     * The Streamable HTTP transport (introduced in 2025-03-26) requires the
     * response to a request to be delivered inline on the POST. The earlier
     * HTTP+SSE transport (2024-11-05) acknowledges the POST and delivers the
     * response over the separately established GET stream.
     */
    public function usesDirectResponse(string $protocolVersion): bool
    {
        return strcmp($protocolVersion, '2025-03-26') >= 0;
    }

    /**
     * Get protocol version from session data (authoritative source)
     */
    public function getSessionVersion(?string $sessionId): string
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $sessionData = $this->storage->getSession($sessionId);

        if (!$sessionData) {
            throw new ProtocolException('Invalid or expired session ID', -32001);
        }

        // Use stored protocol version as authoritative source
        if (isset($sessionData['protocol_version'])) {
            return $sessionData['protocol_version'];
        }

        // Fallback to extracting from sessionId format (protocolVersion_sessionId)
        if (strpos($sessionId, '_') !== false) {
            $parts = explode('_', $sessionId, 2);
            if (count($parts) === 2 && in_array($parts[0], $this->config['supported_versions'] ?? [])) {
                return $parts[0];
            }
        }

        throw new ProtocolException('No protocol version found in session', -32001);
    }

    /**
     * Store protocol version in session data
     */
    public function storeSessionVersion(string $sessionId, string $version): void
    {
        // Get existing session data to preserve other values
        $existingData = $this->storage->getSession($sessionId) ?? [];

        // Update with new protocol version
        $sessionData = array_replace_recursive($existingData, [
            'protocol_version' => $version,
            'updated_at' => time()
        ]);

        $this->storage->storeSession($sessionId, $sessionData);
    }
}
