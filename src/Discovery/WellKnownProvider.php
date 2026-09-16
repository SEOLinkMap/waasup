<?php

namespace Seolinkmap\Waasup\Discovery;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Seolinkmap\Waasup\Config;

class WellKnownProvider
{
    private array $config;

    /**
     * @param array $config config array (master in MCPSaaSServer::getDefaultConfig())
     */
    public function __construct(array $config = [])
    {
        $this->config = Config::merge($this->getDefaultConfig(), $config);
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
        $response->getBody()->write(json_encode($this->buildAuthServerMetadata($request)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * OpenID Connect Discovery 1.0 endpoint
     * Route: /.well-known/openid-configuration
     *
     * Serves the same OAuth 2.1 authorization server metadata, for clients that probe
     * this location. This server issues no ID tokens and is not an OpenID Provider.
     *
     * @param Request $request
     * @param Response $response
     * @return Response JSON metadata with authorization/token/registration endpoints
     */
    public function openidConfiguration(Request $request, Response $response): Response
    {
        $discovery = $this->buildAuthServerMetadata($request);
        $discovery['subject_types_supported'] = ['public'];

        $response->getBody()->write(json_encode($discovery));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Build the authorization server metadata document
     *
     * @return array metadata keyed as RFC 8414 defines
     */
    private function buildAuthServerMetadata(Request $request): array
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

        if (strcmp($protocolVersion, '2025-06-18') >= 0) {
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
            $discovery['require_resource_parameter'] = true;
            $discovery['pkce_methods_supported'] = ['S256'];
        }

        $discovery['pkce_required'] = true;
        $discovery['authorization_response_iss_parameter_supported'] = true;

        return $discovery;
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

        if (strcmp($protocolVersion, '2025-06-18') >= 0) {
            $discovery['resource_server'] = true;
            $discovery['resource_indicators_supported'] = true;
            $discovery['token_binding_supported'] = true;
            $discovery['audience_validation_required'] = true;
            $discovery['resource_indicator_endpoint'] = $this->getOAuthBaseUrl($request) . $this->config['oauth']['resource_server']['endpoints']['resource'];
            $discovery['token_endpoint_auth_methods_supported'] = ['client_secret_post', 'none'];
            $discovery['token_binding_methods_supported'] = ['resource_indicator'];
            $discovery['content_types_supported'] = ['application/json', 'text/event-stream'];
            $discovery['streamable_http_supported'] = true;
            $discovery['mcp_features_supported'] = [
                'tools', 'prompts', 'resources', 'sampling', 'roots', 'ping',
                'progress_notifications', 'tool_annotations', 'audio_content',
                'completions', 'elicitation', 'structured_outputs', 'resource_links'
            ];

            if (strcmp($protocolVersion, '2025-11-25') >= 0) {
                $discovery['mcp_features_supported'][] = 'icons';
                $discovery['mcp_features_supported'][] = 'elicitation_url';
                $discovery['mcp_features_supported'][] = 'sampling_tools';
            }
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

        $baseUrl = $scheme . '://' . $host;
        if (is_numeric($port) && $scheme === 'https' && $port !== 443) {
            $baseUrl .= ':' . $port;
        }

        $wellKnownPattern = '/^\/\.well-known\/oauth-protected-resource(\/.*)?$/';
        if (preg_match($wellKnownPattern, $path, $matches)) {
            $resourcePath = $matches[1] ?? '';

            if (!empty($resourcePath)) {
                $baseUrl .= $resourcePath;
            }
        } else {

            $baseUrl .= $path;
        }

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

        if (strpos($path, '2025-11-25') !== false) {
            return '2025-11-25';
        } elseif (strpos($path, '2025-06-18') !== false) {
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
     * @return array{base_url: null, oauth: array, scopes_supported: string[]}
     */
    private function getDefaultConfig(): array
    {
        return [
            'base_url' => null,
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'oauth' => [
                'base_url' => '',
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
