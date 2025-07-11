<?php

namespace Seolinkmap\Waasup\Tools\Built;

use Seolinkmap\Waasup\Tools\AbstractTool;

/**
 * Built-in server info tool
 */
class ServerInfoTool extends AbstractTool
{
    private array $serverConfig;

    public function __construct(array $serverConfig = [])
    {
        $this->serverConfig = $serverConfig;

        parent::__construct(
            'server_info',
            'Get information about the MCP server',
            [
                'properties' => [
                    'include_context' => [
                        'type' => 'boolean',
                        'description' => 'Include context information if available'
                    ]
                ]
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $info = [
            'server' => $this->serverConfig['server_info'] ?? [
                'name' => 'WaaSuP MCP SaaS Server',
                'version' => '1.0.0'
            ],
            'protocol_version' => '2024-11-05',
            'timestamp' => date('c')
        ];

        if ($parameters['include_context'] ?? false) {
            $info['context'] = [
                'has_context' => !empty($context),
                'context_keys' => array_keys($context)
            ];
        }

        return $info;
    }
}
