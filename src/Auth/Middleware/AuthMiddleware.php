<?php

namespace Seolinkmap\Waasup\Auth\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Seolinkmap\Waasup\Exception\AuthenticationException;
use Seolinkmap\Waasup\Storage\StorageInterface;

/**
 * PSR-15 Authentication Middleware for MCP Server
 *
 * Handles OAuth 2.1 authentication with RFC 8707 Resource Indicators support.
 * Validates bearer tokens, manages context-based authorization, and provides
 * OAuth discovery responses for MCP protocol versions 2024-11-05 through 2025-06-18.
 *
 * @package Seolinkmap\Waasup\Auth\Middleware
 */
class AuthMiddleware
{
    private StorageInterface $storage;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private array $config;

    /**
     * Initialize authentication middleware
     *
     * @param StorageInterface $storage Storage implementation for token/context validation
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory for creating HTTP responses
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory for response bodies
     * @param array $config config array (master in MCPSaaSServer::getDefaultConfig())
     */
    public function __construct(
        StorageInterface $storage,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
    }

    /**
     * PSR-15 middleware that validates OAuth tokens and sets mcp_context attribute
     *
     * @param Request $request
     * @param RequestHandler $handler
     * @return Response OAuth discovery response on auth failure, or handler response
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        try {
            if ($this->config['auth']['authless']) {
                return $this->handleAuthlessRequest($request, $handler);
            }

            $contextId = $this->extractContextId($request);

            if (!$contextId) {
                throw new AuthenticationException('Missing context identifier');
            }

            $contextData = $this->validateContext($contextId);

            if (!$contextData) {
                throw new AuthenticationException('Invalid or inactive context');
            }

            $accessToken = $this->extractAccessToken($request);

            if (!$accessToken) {
                return $this->createOAuthDiscoveryResponse($request);
            }

            $tokenData = $this->validateToken($accessToken, $contextData);

            if (!$tokenData) {
                return $this->createOAuthDiscoveryResponse($request);
            }

            $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version');

            if ($protocolVersion === '2025-06-18') {
                try {
                    $this->validateResourceServerRequirements($request, $tokenData);
                } catch (AuthenticationException $e) {
                    return $this->createErrorResponse('Resource validation failed', 401);
                }
            }

            $sessionId = $request->getHeaderLine('mcp-session-id');

            $context = [
                'context_data' => $contextData,
                'token_data' => $tokenData,
                'context_id' => $contextId,
                'base_url' => $this->getMCPBaseUrl($request),
                'protocol_version' => $protocolVersion,
                'sessionid' => $sessionId
            ];

            $request = $request->withAttribute('mcp_context', $context);

            return $handler->handle($request);
        } catch (AuthenticationException $e) {
            return $this->createOAuthDiscoveryResponse($request);
        } catch (\Exception $e) {
            return $this->createErrorResponse('Internal authentication error', 500);
        }
    }

    protected function extractContextId(Request $request): ?string
    {
        $route = $request->getAttribute('__route__');

        if ($route && method_exists($route, 'getArgument')) {
            $agencyUuid = $route->getArgument('agencyUuid');
            $userId = $route->getArgument('userId');
            $contextId = $route->getArgument('contextId');

            if ($agencyUuid) {
                return $agencyUuid;
            }
            if ($userId) {
                return $userId;
            }
            if ($contextId) {
                return $contextId;
            }
        }

        $path = $request->getUri()->getPath();
        $segments = explode('/', trim($path, '/'));


        foreach ($segments as $segment) {
            if ($this->isValidUuid($segment)) {
                return $segment;
            }
        }
        return null;
    }

    private function handleAuthlessRequest(Request $request, RequestHandler $handler): Response
    {
        $protocolVersion = $this->detectProtocolVersion($request);

        $request = $request->withAttribute(
            'mcp_context',
            [
                'context_data' => 'public',
                'token_data' => null,
                'context_id' => 'public',
                'base_url' => $this->getMCPBaseUrl($request),
                'protocol_version' => $protocolVersion,
                'authless' => true
            ]
        );

        return $handler->handle($request);
    }

    private function validateResourceServerRequirements(Request $request, array $tokenData): void
    {
        $expectedResource = $this->getMCPBaseUrl($request);

        if (!empty($tokenData['resource']) && $tokenData['resource'] !== $expectedResource) {
            throw new AuthenticationException('Token not bound to this resource (RFC 8707 violation)');
        }

        if (!empty($tokenData['aud']) && (!is_array($tokenData['aud']) || !in_array($expectedResource, $tokenData['aud']))) {
            throw new AuthenticationException('Token audience validation failed');
        }

        if (isset($tokenData['scope']) && !$this->validateTokenScope($tokenData['scope'])) {
            throw new AuthenticationException('Token scope invalid for this resource server');
        }
    }

    private function validateTokenScope(string $scope): bool
    {
        $tokenScopes = explode(' ', $scope);
        $requiredScopes = $this->config['auth']['required_scopes'];

        foreach ($requiredScopes as $requiredScope) {
            if (!in_array($requiredScope, $tokenScopes)) {
                return false;
            }
        }

        return true;
    }

    protected function createOAuthDiscoveryResponse(Request $request): Response
    {
        $oauthBaseUrl = $this->getOAuthBaseUrl($request);
        $mcpResourceUrl = $this->getMCPBaseUrl($request);

        $oauthEndpoints = $this->config['oauth']['auth_server']['endpoints'];

        // RFC 9728 Section 3.1: Build proper metadata URLs
        $resourceMetadataUrl = $this->buildResourceMetadataUrl($request);
        $authServerMetadataUrl = $this->buildAuthServerMetadataUrl($request);

        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => 'Authentication required',
                'data' => [
                    'oauth' => [
                        'authorization_endpoint' => $oauthBaseUrl . $oauthEndpoints['authorize'],
                        'token_endpoint' => $oauthBaseUrl . $oauthEndpoints['token'],
                        'registration_endpoint' => $oauthBaseUrl . $oauthEndpoints['register'],
                        'resource' => $mcpResourceUrl,
                        'resource_metadata_endpoint' => $resourceMetadataUrl,
                        'authorization_server_metadata_endpoint' => $authServerMetadataUrl
                    ]
                ]
            ],
            'id' => null
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"jsonrpc":"2.0","error":{"code":-32000,"message":"JSON encoding error"},"id":null}';
        }

        $stream = $this->streamFactory->createStream($jsonContent);

        $response = $this->responseFactory->createResponse(401)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*');

        // RFC 9728 Section 5.1: WWW-Authenticate header with resource_metadata parameter
        $response = $response->withHeader(
            'WWW-Authenticate',
            'Bearer realm="MCP Server", resource_metadata="' . $resourceMetadataUrl . '"'
        );

        return $response;
    }

    /**
     * RFC 9728 Section 3.1: Build resource metadata URL by inserting well-known between host and MCP resource path
     */
    private function buildResourceMetadataUrl(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = 'https'; // Force HTTPS as per RFC 9728
        $host = $uri->getHost();
        $port = $uri->getPort();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        // Build base host URL
        $hostUrl = $scheme . '://' . $host;
        if ($port && $scheme === 'https' && $port !== 443) {
            $hostUrl .= ':' . $port;
        }

        // RFC 9728: Insert /.well-known/oauth-protected-resource between host and MCP resource path
        $metadataUrl = $hostUrl . '/.well-known/oauth-protected-resource';

        // Add the MCP resource path (e.g., /mcp-repo-private/uuid)
        if (!empty($path) && $path !== '/') {
            $metadataUrl .= $path;
        }

        // Add query component if present
        if (!empty($query)) {
            $metadataUrl .= '?' . $query;
        }

        return $metadataUrl;
    }

    /**
     * RFC 8414: Build authorization server metadata URL by inserting well-known between host and OAuth server path
     */
    private function buildAuthServerMetadataUrl(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = 'https'; // Force HTTPS
        $host = $uri->getHost();
        $port = $uri->getPort();

        // Build base host URL
        $hostUrl = $scheme . '://' . $host;
        if ($port && $scheme === 'https' && $port !== 443) {
            $hostUrl .= ':' . $port;
        }

        // Check if we have a custom OAuth base URL configured
        if (!empty($this->config['oauth']['base_url'])) {
            $parsed = parse_url($this->config['oauth']['base_url']);
            $oauthPath = ltrim($parsed['path'] ?? '', '/');

            // If OAuth has a path component, insert well-known correctly
            if (!empty($oauthPath)) {
                return $hostUrl . '/.well-known/oauth-authorization-server/' . $oauthPath;
            }
        }

        // Default: no path for OAuth server
        return $hostUrl . '/.well-known/oauth-authorization-server';
    }

    private function detectProtocolVersion(Request $request): string
    {
        // Check MCP-Protocol-Version header first
        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');
        if ($headerVersion) {
            return $headerVersion;
        }

        // Fallback to path detection
        $path = $request->getUri()->getPath();
        if (strpos($path, '2025-06-18') !== false) {
            return '2025-06-18';
        } elseif (strpos($path, '2025-03-26') !== false) {
            return '2025-03-26';
        }

        return '2024-11-05';
    }

    protected function validateToken(string $accessToken, array $contextData): ?array
    {
        $tokenData = $this->storage->validateToken($accessToken, $contextData);

        if (!$tokenData) {
            return null;
        }

        if ($this->config['auth']['validate_scope'] && isset($tokenData['scope'])) {
            $requiredScopes = $this->config['auth']['required_scopes'];
            $tokenScopes = explode(' ', $tokenData['scope']);

            foreach ($requiredScopes as $requiredScope) {
                if (!in_array($requiredScope, $tokenScopes)) {
                    return null;
                }
            }
        }

        return $tokenData;
    }

    protected function validateContext(string $contextId): ?array
    {
        $contextTypes = $this->config['auth']['context_types'];

        foreach ($contextTypes as $type) {
            $contextData = $this->storage->getContextData($contextId, $type);
            if ($contextData) {
                $contextData['context_type'] = $type;
                return $contextData;
            }
        }

        return null;
    }

    protected function extractAccessToken(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function createErrorResponse(string $message, int $status): Response
    {
        // For OAuth authentication errors (401/403), use OAuth 2.1 Section 5.3 format
        if ($status === 401 || $status === 403) {
            $errorCode = $status === 401 ? 'invalid_token' : 'insufficient_scope';
            $responseData = [
                'error' => $errorCode,
                'error_description' => $message
            ];

            $wwwAuth = 'Bearer realm="MCP Server"';
            $wwwAuth .= ', error="' . $errorCode . '"';
            $wwwAuth .= ', error_description="' . $message . '"';

            $jsonContent = json_encode($responseData);
            if ($jsonContent === false) {
                $jsonContent = '{"error":"invalid_request","error_description":"JSON encoding error"}';
            }

            $stream = $this->streamFactory->createStream($jsonContent);

            return $this->responseFactory->createResponse($status)
                ->withBody($stream)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('WWW-Authenticate', $wwwAuth)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        // Keep existing JSONRPC format for non-OAuth errors
        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32004,
                'message' => $message
            ],
            'id' => null
        ];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"jsonrpc":"2.0","error":{"code":-32004,"message":"JSON encoding error"},"id":null}';
        }

        $stream = $this->streamFactory->createStream($jsonContent);

        return $this->responseFactory->createResponse($status)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    protected function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    protected function getMCPBaseUrl(Request $request): string
    {
        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        $uri = $request->getUri();
        $host = $uri->getHost();
        $path = $uri->getPath();
        $baseUrl = 'https://' . $host . ($uri->getPort() ? ':' . $uri->getPort() : '');

        return $baseUrl . $path;
    }

    protected function getOAuthBaseUrl(Request $request): string
    {
        if (!empty($this->config['oauth']['base_url'])) {
            return $this->config['oauth']['base_url'];
        }

        $uri = $request->getUri();
        $host = $uri->getHost();

        return 'https://' . $host . ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    protected function getDefaultConfig(): array
    {
        return [
            'base_url' => null,
            'auth' => [
                'context_types' => ['agency', 'user'],
                'validate_scope' => true,
                'required_scopes' => ['mcp:read'],
                'authless' => false,
                'authless_context_id' => 'public',
                'authless_context_data' => [
                    'id' => 1,
                    'name' => 'Public Access',
                    'active' => true,
                    'type' => 'public'
                ],
                'authless_token_data' => [
                    'user_id' => 1,
                    'scope' => 'mcp:read',
                    'access_token' => 'authless-access'
                ]
            ],
            'oauth' => [
                'base_url' => '',
                'auth_server' => [
                    'endpoints' => [
                        'authorize' => '/oauth/authorize',
                        'token' => '/oauth/token',
                        'register' => '/oauth/register'
                    ]
                ]
            ]
        ];
    }
}
