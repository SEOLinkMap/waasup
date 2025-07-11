<?php

namespace Seolinkmap\Waasup\Tools\Built;

use Seolinkmap\Waasup\Tools\AbstractTool;

/**
 * Built-in ping tool
 */
class PingTool extends AbstractTool
{
    public function __construct()
    {
        parent::__construct(
            'ping',
            'Test connectivity to the MCP server',
            [
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Optional message to echo back'
                    ]
                ]
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        return [
            'status' => 'pong',
            'timestamp' => date('c'),
            'message' => $parameters['message'] ?? 'Hello from MCP server!',
            'context_available' => !empty($context)
        ];
    }
}
