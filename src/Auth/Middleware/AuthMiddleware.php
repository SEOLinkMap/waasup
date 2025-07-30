<?php

namespace Seolinkmap\Waasup\Auth\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Seolinkmap\Waasup\Exception\AuthenticationException;
use Seolinkmap\Waasup\Storage\StorageInterface;

class AuthMiddleware
{
    private StorageInterface $storage;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private array $config;

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

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        try {
            if ($this->config['auth']['authless']) {
                return $this->handleAuthlessRequest($request, $handler);
            }

            if ($request->getMethod() === 'POST') {
                $body = (string) $request->getBody();
                if (!empty($body)) {
                    $data = json_decode($body, true);
                    if (is_array($data) && isset($data['method']) && $data['method'] === 'initialize') {
                        if ($request->getBody()->isSeekable()) {
                            $request->getBody()->rewind();
                        }
                        return $handler->handle($request);
                    }
                    if ($request->getBody()->isSeekable()) {
                        $request->getBody()->rewind();
                    }
                }
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

            $request = $request->withAttribute(
                'mcp_context',
                [
                    'context_data' => $contextData,
                    'token_data' => $tokenData,
                    'context_id' => $contextId,
                    'base_url' => $this->getMCPBaseUrl($request),
                    'protocol_version' => $protocolVersion
                ]
            );

            return $handler->handle($request);
        } catch (AuthenticationException $e) {
            return $this->createOAuthDiscoveryResponse($request);
        } catch (\Exception $e) {
            return $this->createErrorResponse('Internal authentication error', 500);
        }
    }

    private function handleAuthlessRequest(Request $request, RequestHandler $handler): Response
    {
        $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version');
        $contextId = $this->extractContextId($request) ?? $this->config['auth']['authless_context_id'];

        $contextData = $this->config['auth']['authless_context_data'];
        $tokenData = $this->config['auth']['authless_token_data'];

        $request = $request->withAttribute(
            'mcp_context',
            [
                'context_data' => $contextData,
                'token_data' => $tokenData,
                'context_id' => $contextId,
                'base_url' => $this->getMCPBaseUrl($request),
                'protocol_version' => $protocolVersion,
                'authless' => true
            ]
        );

        return $handler->handle($request);
    }

    private function validateResourceServerRequirements(Request $request, array $tokenData): void
    {
        $baseUrl = $this->getMCPBaseUrl($request);
        $contextId = $this->extractContextId($request);
        $expectedResource = $baseUrl . '/mcp/' . $contextId;

        if (!isset($tokenData['resource']) || $tokenData['resource'] !== $expectedResource) {
            throw new AuthenticationException('Token not bound to this resource (RFC 8707 violation)');
        }

        if (!isset($tokenData['aud']) || !in_array($expectedResource, (array)$tokenData['aud'])) {
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
        $mcpBaseUrl = $this->getMCPBaseUrl($request);

        $oauthEndpoints = $this->config['oauth']['auth_server']['endpoints'];

        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => 'Authentication required',
                'data' => [
                    'oauth' => [
                        'authorization_endpoint' => $oauthBaseUrl . $oauthEndpoints['authorize'],
                        'token_endpoint' => $oauthBaseUrl . $oauthEndpoints['token'],
                        'registration_endpoint' => $oauthBaseUrl . $oauthEndpoints['register']
                    ]
                ]
            ],
            'id' => null
        ];

        $contextId = $this->extractContextId($request);

        $wellknownEndpoints = $this->config['discovery']['wellknown'];

        $responseData['error']['data']['oauth']['resource'] = $mcpBaseUrl;
        $responseData['error']['data']['oauth']['resource_metadata_endpoint'] = $oauthBaseUrl . $wellknownEndpoints['protected_resource'];
        $responseData['error']['data']['oauth']['authorization_server_metadata_endpoint'] = $oauthBaseUrl . $wellknownEndpoints['auth_server'];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"jsonrpc":"2.0","error":{"code":-32000,"message":"JSON encoding error"},"id":null}';
        }

        $stream = $this->streamFactory->createStream($jsonContent);

        $response = $this->responseFactory->createResponse(401)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*');

        $resourceMetadataUrl = $oauthBaseUrl . $wellknownEndpoints['protected_resource'];
        $response = $response->withHeader(
            'WWW-Authenticate',
            'Bearer realm="MCP Server", resource_metadata="' . $resourceMetadataUrl . '"'
        );

        return $response;
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

    protected function extractContextId(Request $request): ?string
    {
        $route = $request->getAttribute('__route__');

        if ($route && method_exists($route, 'getArgument')) {
            $contextId = $route->getArgument('agencyUuid') ??
                        $route->getArgument('userId') ??
                        $route->getArgument('contextId');

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

    /**
     * Get MCP base URL for resource operations (tenant-specific)
     */
    protected function getMCPBaseUrl(Request $request): string
    {
        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        return $scheme . '://' . $host . ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    /**
     * Get OAuth base URL for auth operations (shared infrastructure)
     */
    protected function getOAuthBaseUrl(Request $request): string
    {
        if (!empty($this->config['oauth']['base_url'])) {
            return $this->config['oauth']['base_url'];
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        return $scheme . '://' . $host . ($uri->getPort() ? ':' . $uri->getPort() : '');
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
            'discovery' => [
                'wellknown' => [
                    'auth_server' => '/.well-known/oauth-authorization-server',
                    'protected_resource' => '/.well-known/oauth-protected-resource'
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
