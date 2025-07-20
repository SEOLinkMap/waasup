<?php

namespace Seolinkmap\Waasup\Protocol;

/**
 * Handles MCP protocol version negotiation
 */
class VersionNegotiator
{
    private array $supportedVersions;

    public function __construct(array $supportedVersions = ['2025-06-18', '2025-03-26', '2024-11-05'])
    {
        $this->supportedVersions = $supportedVersions;
    }

    /**
     * Negotiate the best version based on client request
     */
    public function negotiate(string $clientVersion): string
    {
        // Find newest version we support that client can handle
        foreach ($this->supportedVersions as $version) {
            if ($version <= $clientVersion) {
                return $version;
            }
        }

        // Fallback to oldest supported version
        return end($this->supportedVersions);
    }

    /**
     * Check if a version is supported
     */
    public function isSupported(string $version): bool
    {
        return in_array($version, $this->supportedVersions);
    }

    /**
     * Get all supported versions
     */
    public function getSupportedVersions(): array
    {
        return $this->supportedVersions;
    }
}
