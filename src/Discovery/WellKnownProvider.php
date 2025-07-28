<?php

namespace Seolinkmap\Waasup\Discovery;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WellKnownProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
    }

    public function protectedResource(Request $request, Response $response): Response
    {
        $baseUrl = $this->getBaseUrl($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: $this->detectProtocolFromPath($request);

        $discovery = [
            'resource' => $baseUrl,
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $this->config['scopes_supported'] ?? ['mcp:read', 'mcp:write']
        ];

        if ($protocolVersion === '2025-06-18') {
            $discovery['resource_server'] = true;
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_supported'] = true;
            $discovery['audience_validation_required'] = true;
            $discovery['resource_indicator_endpoint'] = $baseUrl . $this->config['oauth']['resource_server']['endpoints']['resource'];
            $discovery['token_endpoint_auth_methods_supported'] = ['client_secret_post', 'private_key_jwt'];
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
            $discovery['content_types_supported'] = ['application/json', 'text/event-stream'];
            $discovery['mcp_features_supported'] = [
                'tools', 'prompts', 'resources', 'sampling', 'roots', 'ping',
                'progress_notifications', 'tool_annotations', 'audio_content',
                'completions', 'elicitation', 'structured_outputs', 'resource_links'
            ];
        } elseif ($protocolVersion === '2025-03-26') {
            $discovery['streamable_http_supported'] = true;
            $discovery['json_rpc_batching_supported'] = true;
            $discovery['mcp_features_supported'] = [
                'tools', 'prompts', 'resources', 'sampling', 'roots', 'ping',
                'progress_notifications', 'tool_annotations', 'audio_content', 'completions'
            ];
        } else {
            $discovery['http_sse_supported'] = true;
            $discovery['mcp_features_supported'] = [
                'tools', 'prompts', 'resources', 'sampling', 'roots', 'ping', 'progress_notifications'
            ];
        }

        $response->getBody()->write(json_encode($discovery));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function authorizationServer(Request $request, Response $response): Response
    {
        $baseUrl = $this->getBaseUrl($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: '2024-11-05';

        $discovery = [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . $this->config['oauth']['auth_server']['endpoints']['authorize'],
            'token_endpoint' => $baseUrl . $this->config['oauth']['auth_server']['endpoints']['token'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'response_modes_supported' => ['query'],
            'registration_endpoint' => $baseUrl . $this->config['oauth']['auth_server']['endpoints']['register'],
            'scopes_supported' => $this->config['scopes_supported'] ?? ['mcp:read']
        ];

        if (!empty($this->config['oauth']['auth_server']['endpoints']['revoke'])) {
            $discovery['revocation_endpoint'] = $baseUrl . $this->config['oauth']['auth_server']['endpoints']['revoke'];
        }

        if ($protocolVersion === '2025-06-18') {
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
            $discovery['require_resource_parameter'] = true;
            $discovery['pkce_methods_supported'] = ['S256'];
            $discovery['token_endpoint_auth_methods_supported'] = ['client_secret_post', 'private_key_jwt', 'none'];
        }

        if (in_array($protocolVersion, ['2024-11-05', '2025-03-26', '2025-06-18'])) {
            $discovery['pkce_required'] = true;
            $discovery['authorization_response_iss_parameter_supported'] = true;
        }

        $response->getBody()->write(json_encode($discovery));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getBaseUrl(Request $request): string
    {
        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() .
               ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    private function detectProtocolFromPath(Request $request): string
    {
        $path = $request->getUri()->getPath();

        if (strpos($path, '2025-06-18') !== false) {
            return '2025-06-18';
        } elseif (strpos($path, '2025-03-26') !== false) {
            return '2025-03-26';
        }

        return '2024-11-05';
    }

    private function getDefaultConfig(): array
    {
        return [
            'base_url' => null,
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'oauth' => [
                'auth_server' => [
                    'endpoints' => [
                        'authorize' => '/oauth/authorize',
                        'token' => '/oauth/token',
                        'register' => '/oauth/register',
                        'revoke' => '/oauth/revoke'
                    ]
                ],
                'resource_server' => [
                    'endpoints' => [
                        'resource' => '/oauth/resource'
                    ]
                ]
            ]
        ];
    }
}
