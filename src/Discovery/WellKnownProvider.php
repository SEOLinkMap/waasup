<?php

namespace Seolinkmap\Waasup\Discovery;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Provides OAuth discovery endpoints (.well-known)
 */
class WellKnownProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * OAuth protected resource discovery with MCP 2025-06-18 enhancements
     */
    public function protectedResource(Request $request, Response $response): Response
    {
        $baseUrl = $this->getBaseUrl($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: '2024-11-05';

        $discovery = [
            'resource' => $baseUrl,
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $this->config['scopes_supported'] ?? ['mcp:read']
        ];

        // Add OAuth Resource Server metadata for MCP 2025-06-18
        if ($protocolVersion === '2025-06-18') {
            $discovery['resource_server'] = true;
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_supported'] = true;
        }

        $response->getBody()->write(json_encode($discovery));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * OAuth authorization server discovery with version support
     */
    public function authorizationServer(Request $request, Response $response): Response
    {
        $baseUrl = $this->getBaseUrl($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: '2024-11-05';

        $discovery = [
            'issuer' => $baseUrl,
            'authorization_endpoint' => "{$baseUrl}/oauth/authorize",
            'token_endpoint' => "{$baseUrl}/oauth/token",
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'response_modes_supported' => ['query'],
            'registration_endpoint' => "{$baseUrl}/oauth/register",
            'scopes_supported' => $this->config['scopes_supported'] ?? ['mcp:read']
        ];

        // Add resource indicators support for MCP 2025-06-18
        if ($protocolVersion === '2025-06-18') {
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
        }

        $response->getBody()->write(json_encode($discovery));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getBaseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() .
               ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    private function getDefaultConfig(): array
    {
        return [
            'scopes_supported' => ['mcp:read', 'mcp:write']
        ];
    }
}
