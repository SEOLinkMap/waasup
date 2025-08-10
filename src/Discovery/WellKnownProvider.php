<?php

namespace Seolinkmap\Waasup\Discovery;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WellKnownProvider
{
    private array $config;

    /**
     * @param array $config config array (master in MCPSaaSServer::getDefaultConfig())
     */
    public function __construct(array $config = [])
    {
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
    }

    /**
     * RFC 8414 OAuth Authorization Server Metadata endpoint
     * Route: /.well-known/oauth-authorization-server
     *
     * @param Request $request
     * @param Response $response
     * @return Response JSON metadata with authorization/token/registration endpoints
     */
    public function authorizationServer(Request $request, Response $response): Response
    {
        $oauthBaseUrl = $this->getOAuthBaseUrl($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: $this->detectProtocolFromPath($request);

        $discovery = [
            'issuer' => $oauthBaseUrl,
            'authorization_endpoint' => $oauthBaseUrl . $this->config['oauth']['auth_server']['endpoints']['authorize'],
            'token_endpoint' => $oauthBaseUrl . $this->config['oauth']['auth_server']['endpoints']['token'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'response_modes_supported' => ['query'],
            'registration_endpoint' => $oauthBaseUrl . $this->config['oauth']['auth_server']['endpoints']['register'],
            'scopes_supported' => $this->config['scopes_supported']
        ];

        $discovery['revocation_endpoint'] = $oauthBaseUrl . $this->config['oauth']['auth_server']['endpoints']['revoke'];

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

    /**
     * RFC 9728 OAuth Protected Resource Metadata endpoint
     * Route: /.well-known/oauth-protected-resource
     *
     * @param Request $request
     * @param Response $response
     * @return Response JSON metadata with resource server capabilities
     */
    public function protectedResource(Request $request, Response $response): Response
    {
        $resourceUrl = $this->extractResourceIdentifier($request);
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version') ?: $this->detectProtocolFromPath($request);

        $discovery = [
            'resource' => $resourceUrl,
            'authorization_servers' => [$this->getOAuthBaseUrl($request)],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $this->config['scopes_supported']
        ];

        if ($protocolVersion === '2025-06-18') {
            $discovery['resource_server'] = true;
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_supported'] = true;
            $discovery['audience_validation_required'] = true;
            $discovery['resource_indicator_endpoint'] = $this->getOAuthBaseUrl($request) . $this->config['oauth']['resource_server']['endpoints']['resource'];
            $discovery['token_endpoint_auth_methods_supported'] = ['client_secret_post', 'private_key_jwt'];
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
            $discovery['content_types_supported'] = ['application/json', 'text/event-stream'];
            $discovery['streamable_http_supported'] = true;
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

    /**
     * RFC 9728 Section 3.3: Extract resource identifier from request URL
     * The resource identifier is reconstructed by removing the well-known path
     */
    private function extractResourceIdentifier(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        // Build base URL
        $baseUrl = $scheme . '://' . $host;
        if (is_numeric($port) && $scheme === 'https' && $port !== 443) {
            $baseUrl .= ':' . $port;
        }

        // Remove the well-known path component to get the original resource identifier
        // Example: /.well-known/oauth-protected-resource/some/path -> /some/path
        $wellKnownPattern = '/^\/\.well-known\/oauth-protected-resource(\/.*)?$/';
        if (preg_match($wellKnownPattern, $path, $matches)) {
            $resourcePath = $matches[1] ?? '';
            // If there's a path after the well-known part, that's the resource path
            if (!empty($resourcePath)) {
                $baseUrl .= $resourcePath;
            }
        } else {
            // If the pattern doesn't match, include the full path (fallback)
            $baseUrl .= $path;
        }

        // Add query string if present
        if (!empty($query)) {
            $baseUrl .= '?' . $query;
        }

        return $baseUrl;
    }

    /**
     * Get OAuth base URL for auth operations
     */
    private function getOAuthBaseUrl(Request $request): string
    {
        if (!empty($this->config['oauth']['base_url'])) {
            return $this->config['oauth']['base_url'];
        }

        $uri = $request->getUri();
        return 'https://' . $uri->getHost() .
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

    /**
     * The oauth typically has any "path" in the baseURL and the endpoints are typically /authorize.
     * The "weird" config below works when baseURL is empty and the system sniffs the domain root.
     *
     * You are best off not touching this, or including your oauth "path" in the baseURL before defining each endpoint.
     *
     * @return array{base_url: null, oauth: array, scopes_supported: string[]}
     */
    private function getDefaultConfig(): array
    {
        return [
            'base_url' => null, // MCP baseURL
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'oauth' => [
                'base_url' => '', // OAuth baseURL
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
