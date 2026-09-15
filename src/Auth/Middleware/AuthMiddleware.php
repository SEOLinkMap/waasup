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

            $missingScopes = $this->missingScopes($tokenData['scope'] ?? '');

            if ($missingScopes !== []) {
                return $this->createInsufficientScopeResponse($request, $tokenData['scope'] ?? '');
            }

            $tokenData = $this->applySlidingExpiration($accessToken, $tokenData);

            $protocolVersion = $request->getHeaderLine('MCP-Protocol-Version');

            try {
                $this->validateResourceServerRequirements($request, $tokenData);
            } catch (AuthenticationException $e) {
                return $this->createErrorResponse($e->getMessage(), 401);
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

    /**
     * Validate the token's resource binding against this server
     *
     * @throws AuthenticationException when the binding does not cover this server
     */
    private function validateResourceServerRequirements(Request $request, array $tokenData): void
    {
        $expectedResource = $this->getMCPBaseUrl($request);

        $requireBinding = $this->config['oauth']['resource_server']['require_resource_binding']
            && !empty($this->config['base_url']);

        if (empty($tokenData['resource']) && empty($tokenData['aud'])) {
            if ($requireBinding) {
                throw new AuthenticationException('This server only accepts tokens bound to it. Request a token with resource=' . $expectedResource . ' and present that one.');
            }

            return;
        }

        if (empty($this->config['base_url'])) {
            throw new AuthenticationException('This token is bound to a resource, but the server has no base_url configured to check it against. Set base_url to this endpoint URL.');
        }

        if (!empty($tokenData['resource']) && !$this->coversResource($tokenData['resource'], $expectedResource)) {
            throw new AuthenticationException('This token was issued for a different resource. Request a token with resource=' . $expectedResource . '.');
        }

        if (!empty($tokenData['aud'])) {
            $audiences = is_string($tokenData['aud']) ? json_decode($tokenData['aud'], true) : $tokenData['aud'];

            if (!is_array($audiences)) {
                $audiences = [$tokenData['aud']];
            }

            $validAudience = false;
            foreach ($audiences as $audience) {
                if (is_string($audience) && $this->coversResource($audience, $expectedResource)) {
                    $validAudience = true;
                    break;
                }
            }

            if (!$validAudience) {
                throw new AuthenticationException('This token names a different audience. Request a token with resource=' . $expectedResource . '.');
            }
        }

    }

    /**
     * Check whether a token's resource identifier names this server
     *
     * The token matches when it names this exact endpoint, or a parent path of it,
     * so a token bound to one tenant cannot be spent against another.
     *
     * @param string $bound the identifier recorded on the token
     * @param string $expected the resource identifier of this server
     */
    private function coversResource(string $bound, string $expected): bool
    {
        $parsed = parse_url($bound);

        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $bound = rtrim($bound, '/');
        $expected = rtrim($expected, '/');

        if ($bound === $expected) {
            return true;
        }

        return str_starts_with($expected, $bound . '/');
    }

    /**
     * Required scopes the presented token is missing
     *
     * @param string $scope the space delimited scopes the token carries
     * @return array the required scopes absent from the token
     */
    private function missingScopes(string $scope): array
    {
        if (!$this->config['auth']['validate_scope']) {
            return [];
        }

        $granted = explode(' ', trim($scope));

        return array_values(array_diff($this->config['auth']['required_scopes'], $granted));
    }

    /**
     * Answer a valid token that lacks the scopes this resource requires
     *
     * @param string $granted the space delimited scopes the token carries
     */
    private function createInsufficientScopeResponse(Request $request, string $granted): Response
    {
        $scopes = array_values(
            array_unique(
                array_filter(
                    array_merge(explode(' ', trim($granted)), $this->config['auth']['required_scopes'])
                )
            )
        );

        $description = 'The presented token is missing a scope this resource requires.';

        $wwwAuth = 'Bearer error="insufficient_scope"'
            . ', scope="' . $this->headerSafe(implode(' ', $scopes)) . '"'
            . ', resource_metadata="' . $this->headerSafe($this->buildResourceMetadataUrl($request)) . '"'
            . ', error_description="' . $this->headerSafe($description) . '"';

        $jsonContent = json_encode(
            [
                'error' => 'insufficient_scope',
                'error_description' => $description,
                'scope' => implode(' ', $scopes)
            ]
        );

        $stream = $this->streamFactory->createStream($jsonContent === false ? '{"error":"insufficient_scope"}' : $jsonContent);

        return $this->responseFactory->createResponse(403)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', $wwwAuth)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * Strip anything that cannot travel in a quoted header parameter
     */
    private function headerSafe(string $value): string
    {
        return str_replace(['"', '\\', "\r", "\n"], '', $value);
    }

    protected function createOAuthDiscoveryResponse(Request $request): Response
    {
        $oauthBaseUrl = $this->getOAuthBaseUrl($request);
        $mcpResourceUrl = $this->getMCPBaseUrl($request);

        $oauthEndpoints = $this->config['oauth']['auth_server']['endpoints'];

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

        $scopes = implode(' ', $this->config['auth']['required_scopes']);

        $response = $response->withHeader(
            'WWW-Authenticate',
            'Bearer realm="MCP Server"'
            . ', resource_metadata="' . $this->headerSafe($resourceMetadataUrl) . '"'
            . ', scope="' . $this->headerSafe($scopes) . '"'
        );

        return $response;
    }

    /**
     * RFC 9728 Section 3.1: Build resource metadata URL by inserting well-known between host and MCP resource path
     */
    private function buildResourceMetadataUrl(Request $request): string
    {
        $uri = $request->getUri();
        $scheme = 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        $hostUrl = $scheme . '://' . $host;
        if ($port && $scheme === 'https' && $port !== 443) {
            $hostUrl .= ':' . $port;
        }

        $metadataUrl = $hostUrl . '/.well-known/oauth-protected-resource';

        if (!empty($path) && $path !== '/') {
            $metadataUrl .= $path;
        }

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
        $scheme = 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();

        $hostUrl = $scheme . '://' . $host;
        if ($port && $scheme === 'https' && $port !== 443) {
            $hostUrl .= ':' . $port;
        }

        if (!empty($this->config['oauth']['base_url'])) {
            $parsed = parse_url($this->config['oauth']['base_url']);
            $oauthPath = ltrim($parsed['path'] ?? '', '/');

            if (!empty($oauthPath)) {
                return $hostUrl . '/.well-known/oauth-authorization-server/' . $oauthPath;
            }
        }

        return $hostUrl . '/.well-known/oauth-authorization-server';
    }

    private function detectProtocolVersion(Request $request): string
    {

        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');
        if ($headerVersion) {
            return $headerVersion;
        }

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

    protected function validateToken(string $accessToken, array $contextData): ?array
    {
        $tokenData = $this->storage->validateToken($accessToken, $contextData);

        if (!$tokenData) {
            return null;
        }

        return $tokenData;
    }

    /**
     * Extend a valid token's expiry so active clients keep their lease
     *
     * @param string $accessToken the presented bearer token
     * @param array $tokenData validated token data
     * @return array token data with the extended expiry when one was applied
     */
    protected function applySlidingExpiration(string $accessToken, array $tokenData): array
    {
        if (empty($this->config['oauth']['sliding_expiration'])) {
            return $tokenData;
        }

        $currentExpiry = $this->normalizeTimestamp($tokenData['expires_at'] ?? null);
        if ($currentExpiry === null) {
            return $tokenData;
        }

        $newExpiry = time() + (int)$this->config['oauth']['access_token_lifetime'];

        $maxLifetime = $this->config['oauth']['sliding_expiration_max_lifetime'];
        $issuedAt = $this->normalizeTimestamp($tokenData['created_at'] ?? null);
        if ($maxLifetime !== null && $issuedAt !== null) {
            $newExpiry = min($newExpiry, $issuedAt + (int)$maxLifetime);
        }

        $interval = (int)$this->config['oauth']['sliding_expiration_interval'];

        if ($newExpiry <= $currentExpiry || ($newExpiry - $currentExpiry) < $interval) {
            return $tokenData;
        }

        if (!$this->storage->touchAccessToken($accessToken, $newExpiry)) {
            return $tokenData;
        }

        $tokenData['expires_at'] = is_numeric($tokenData['expires_at'] ?? null)
            ? $newExpiry
            : date('Y-m-d H:i:s', $newExpiry);

        return $tokenData;
    }

    /**
     * Convert a stored timestamp to a unix timestamp
     *
     * @param mixed $value unix timestamp or datetime string
     * @return int|null unix timestamp, or null when unreadable
     */
    protected function normalizeTimestamp($value): ?int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
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

        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function createErrorResponse(string $message, int $status): Response
    {

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
        $path = $request->getUri()->getPath();

        if (!empty($this->config['base_url'])) {
            $baseUrl = rtrim($this->config['base_url'], '/');

            if ($path !== '' && $path !== '/' && !str_ends_with($baseUrl, $path)) {
                $baseUrl .= $path;
            }

            return $baseUrl;
        }

        $uri = $request->getUri();
        $baseUrl = 'https://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');

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
                'resource_server' => [
                    'require_resource_binding' => false
                ],
                'access_token_lifetime' => 3600,
                'sliding_expiration' => false,
                'sliding_expiration_max_lifetime' => null,
                'sliding_expiration_interval' => 60,
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
