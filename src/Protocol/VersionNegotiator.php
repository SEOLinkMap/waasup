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
        // Convert to comparable format or use semantic version comparison
        $clientVersionTime = strtotime($clientVersion);

        foreach ($this->supportedVersions as $version) {
            $versionTime = strtotime($version);
            if ($versionTime <= $clientVersionTime) {
                return $version;
            }
        }
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
