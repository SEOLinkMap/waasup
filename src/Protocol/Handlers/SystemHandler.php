<?php

namespace Seolinkmap\Waasup\Protocol\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Seolinkmap\Waasup\Exception\ProtocolException;

class SystemHandler
{
    private ProtocolManager $protocolManager;
    private ResponseManager $responseManager;
    private array $config;

    public function __construct(
        ProtocolManager $protocolManager,
        ResponseManager $responseManager,
        array $config = []
    ) {
        $this->protocolManager = $protocolManager;
        $this->responseManager = $responseManager;
        $this->config = $config;
    }

    public function handleInitialize(array $params, mixed $id, ?string $sessionId, string $selectedVersion, Response $response): Response
    {
        if (!isset($params['protocolVersion']) || empty($params['protocolVersion'])) {
            throw new ProtocolException('Invalid params: protocolVersion required', -32602);
        }

        if ($sessionId) {
            $this->protocolManager->storeSessionVersion($sessionId, $selectedVersion);
        }

        $serverInfo = $this->config['server_info'];

        $capabilities = [
            'logging' => new \stdClass()
        ];

        if ($this->protocolManager->isFeatureSupported('tools', $selectedVersion)) {
            $capabilities['tools'] = ['listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('prompts', $selectedVersion)) {
            $capabilities['prompts'] = ['listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('resources', $selectedVersion)) {
            $capabilities['resources'] = ['subscribe' => true, 'listChanged' => true];
        }

        if ($this->protocolManager->isFeatureSupported('completions', $selectedVersion)) {
            $capabilities['completions'] = new \stdClass();
        }

        if ($this->protocolManager->isFeatureSupported('elicitation', $selectedVersion)) {
            $capabilities['elicitation'] = new \stdClass();
        }

        if ($this->protocolManager->isFeatureSupported('sampling', $selectedVersion)) {
            $capabilities['sampling'] = new \stdClass();
        }

        if ($this->protocolManager->isFeatureSupported('roots', $selectedVersion)) {
            $capabilities['roots'] = ['listChanged' => true];
        }

        $result = [
        'protocolVersion' => $selectedVersion,
        'capabilities' => $capabilities,
        'serverInfo' => $serverInfo
        ];

        $responseData = [
        'jsonrpc' => '2.0',
        'result' => $result,
        'id' => $id
        ];

        // $response->getBody()->write(json_encode($responseData));
        $jsonData = json_encode($responseData);

        $response->getBody()->write($jsonData);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', $sessionId)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, MCP-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->withStatus(200);
    }

    public function handlePing(mixed $id, ?string $sessionId, array $context, Response $response): Response
    {
        if (!$sessionId) {
            throw new ProtocolException('Session required', -32001);
        }

        $result = [
            'status' => 'pong',
            'timestamp' => date('c')
        ];

        return $this->responseManager->storeSuccessResponse($sessionId, $result, $id, $response);
    }
}
