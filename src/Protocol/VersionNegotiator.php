<?php

namespace Seolinkmap\Waasup\Protocol;

use Seolinkmap\Waasup\Config;
use Seolinkmap\Waasup\Protocol\Handlers\ProtocolManager;

/**
 * Handles MCP protocol version negotiation
 */
class VersionNegotiator
{
    private array $supportedVersions;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = Config::merge($this->getDefaultConfig(), $config);

        ProtocolManager::assertKnownVersions($this->config['supported_versions']);

        $this->supportedVersions = $this->config['supported_versions'];
    }

    /**
     * Negotiate the best version based on client request
     *
     * The newest version at or below what the client asked for, or the newest this
     * server speaks when it asked for something older than all of them. The order
     * the versions were configured in does not affect the answer.
     */
    public function negotiate(string $clientVersion): string
    {
        if ($this->isSupported($clientVersion)) {
            return $clientVersion;
        }

        $clientVersionTime = strtotime($clientVersion);

        if ($clientVersionTime === false) {
            return $this->newest($this->supportedVersions);
        }

        $candidates = array_filter(
            $this->supportedVersions,
            fn (string $version) => strtotime($version) <= $clientVersionTime
        );

        return $this->newest($candidates === [] ? $this->supportedVersions : $candidates);
    }

    /**
     * The most recent of a set of versions
     *
     * @param string[] $versions version identifiers
     * @return string the newest, empty when none were given
     */
    private function newest(array $versions): string
    {
        $newest = '';

        foreach ($versions as $version) {
            if (strcmp($version, $newest) > 0) {
                $newest = $version;
            }
        }

        return $newest;
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

    private function getDefaultConfig(): array
    {
        return [
            'supported_versions' => ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']
        ];
    }
}
