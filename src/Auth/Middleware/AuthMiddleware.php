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
        $logFile = '/var/www/devsa/logs/uncaught.log';

        $serverName = $this->config['server_info']['name'] ?? 'UNKNOWN';
        $authMode = $this->config['auth']['authless'] ? 'AUTHLESS' : 'OAUTH';
        $serverTag = "[AUTH DEBUG - {$serverName} - {$authMode}]";

        try {
            // LOG ALL REQUEST DETAILS
            file_put_contents($logFile, "{$serverTag} === REQUEST START ===\n", FILE_APPEND);
            file_put_contents($logFile, "{$serverTag} Method: {$request->getMethod()}\n", FILE_APPEND);
            file_put_contents($logFile, "{$serverTag} URI: {$request->getUri()}\n", FILE_APPEND);

            // LOG ALL HEADERS
            file_put_contents($logFile, "{$serverTag} Headers:\n", FILE_APPEND);
            foreach ($request->getHeaders() as $name => $values) {
                file_put_contents($logFile, "{$serverTag}   {$name}: " . implode(', ', $values) . "\n", FILE_APPEND);
            }

            if ($this->config['auth']['authless']) {
                file_put_contents($logFile, "{$serverTag} Authless mode enabled\n", FILE_APPEND);
                return $this->handleAuthlessRequest($request, $handler);
            }

            file_put_contents($logFile, "{$serverTag} OAuth mode - checking initialize method\n", FILE_APPEND);

            if ($request->getMethod() === 'POST') {
                $body = (string) $request->getBody();
                if (!empty($body)) {
                    file_put_contents($logFile, "{$serverTag} Request body: {$body}\n", FILE_APPEND);
                    $data = json_decode($body, true);
                    if (is_array($data) && isset($data['method']) && $data['method'] === 'initialize') {
                        file_put_contents($logFile, "{$serverTag} Initialize method detected, skipping auth\n", FILE_APPEND);
                        $contextId = $this->extractContextId($request);
                        if ($contextId) {
                            $contextData = $this->validateContext($contextId);
                            if ($contextData) {
                                $context = [
                                    'context_data' => $contextData,
                                    'context_id' => $contextId,
                                    'base_url' => $this->getMCPBaseUrl($request),
                                    'initialize_request' => true
                                ];
                                $request = $request->withAttribute('mcp_context', $context);
                            }
                        }
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

            file_put_contents($logFile, "{$serverTag} Extracting context ID\n", FILE_APPEND);
            $contextId = $this->extractContextId($request);
            file_put_contents($logFile, "{$serverTag} Context ID: " . ($contextId ?? 'NULL') . "\n", FILE_APPEND);

            if (!$contextId) {
                file_put_contents($logFile, "{$serverTag} No context ID - throwing AuthenticationException\n", FILE_APPEND);
                throw new AuthenticationException('Missing context identifier');
            }

            file_put_contents($logFile, "{$serverTag} Validating context\n", FILE_APPEND);
            $contextData = $this->validateContext($contextId);
            file_put_contents($logFile, "{$serverTag} Context data: " . json_encode($contextData) . "\n", FILE_APPEND);

            if (!$contextData) {
                file_put_contents($logFile, "{$serverTag} Invalid context - throwing AuthenticationException\n", FILE_APPEND);
                throw new AuthenticationException('Invalid or inactive context');
            }

            file_put_contents($logFile, "{$serverTag} Extracting access token\n", FILE_APPEND);
            $accessToken = $this->extractAccessToken($request);
            file_put_contents($logFile, "{$serverTag} Actual token value: " . $accessToken . "\n", FILE_APPEND);

            if (!$accessToken) {
                file_put_contents($logFile, "{$serverTag} No access token - returning OAuth discovery\n", FILE_APPEND);
                return $this->createOAuthDiscoveryResponse($request);
            }

            file_put_contents($logFile, "{$serverTag} Validating token\n", FILE_APPEND);
            $tokenData = $this->validateToken($accessToken, $contextData);
            file_put_contents($logFile, "{$serverTag} Token data: " . json_encode($tokenData) . "\n", FILE_APPEND);

            if (!$tokenData) {
                file_put_contents($logFile, "{$serverTag} Invalid token - returning OAuth discovery\n", FILE_APPEND);
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

            $context = [
                'context_data' => $contextData,
                'token_data' => $tokenData,
                'context_id' => $contextId,
                'base_url' => $this->getMCPBaseUrl($request),
                'protocol_version' => $protocolVersion
            ];

            file_put_contents($logFile, "{$serverTag} Setting context and continuing: " . json_encode($context) . "\n", FILE_APPEND);

            $request = $request->withAttribute('mcp_context', $context);


            file_put_contents($logFile, "[AUTH-HANDOFF] Headers being passed to handler:\n", FILE_APPEND);
            foreach ($request->getHeaders() as $name => $values) {
                file_put_contents($logFile, "[AUTH-HANDOFF] '$name' = '" . implode(', ', $values) . "'\n", FILE_APPEND);
            }

            return $handler->handle($request);

        } catch (AuthenticationException $e) {
            file_put_contents($logFile, "{$serverTag} AuthenticationException caught: " . $e->getMessage() . "\n", FILE_APPEND);
            return $this->createOAuthDiscoveryResponse($request);
        } catch (\Exception $e) {
            file_put_contents($logFile, "{$serverTag} Other exception caught: " . $e->getMessage() . "\n", FILE_APPEND);
            return $this->createErrorResponse('Internal authentication error', 500);
        }
    }

    protected function extractContextId(Request $request): ?string
    {
        $logFile = '/var/www/devsa/logs/uncaught.log';

        $serverName = $this->config['server_info']['name'] ?? 'UNKNOWN';
        $authMode = $this->config['auth']['authless'] ? 'AUTHLESS' : 'OAUTH';
        $serverTag = "[EXTRACT DEBUG - {$serverName} - {$authMode}]";

        file_put_contents($logFile, "{$serverTag} Starting extractContextId\n", FILE_APPEND);

        $route = $request->getAttribute('__route__');
        file_put_contents($logFile, "{$serverTag} Route object: " . ($route ? get_class($route) : 'NULL') . "\n", FILE_APPEND);

        if ($route && method_exists($route, 'getArgument')) {
            $agencyUuid = $route->getArgument('agencyUuid');
            $userId = $route->getArgument('userId');
            $contextId = $route->getArgument('contextId');

            file_put_contents($logFile, "{$serverTag} agencyUuid from route: " . ($agencyUuid ?? 'NULL') . "\n", FILE_APPEND);
            file_put_contents($logFile, "{$serverTag} userId from route: " . ($userId ?? 'NULL') . "\n", FILE_APPEND);
            file_put_contents($logFile, "{$serverTag} contextId from route: " . ($contextId ?? 'NULL') . "\n", FILE_APPEND);

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

        file_put_contents($logFile, "{$serverTag} Path: $path\n", FILE_APPEND);
        file_put_contents($logFile, "{$serverTag} Segments: " . json_encode($segments) . "\n", FILE_APPEND);

        foreach ($segments as $segment) {
            file_put_contents($logFile, "{$serverTag} Checking segment: $segment\n", FILE_APPEND);
            if ($this->isValidUuid($segment)) {
                file_put_contents($logFile, "{$serverTag} Found valid UUID: $segment\n", FILE_APPEND);
                return $segment;
            }
        }

        file_put_contents($logFile, "{$serverTag} No context ID found\n", FILE_APPEND);
        return null;
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
        $expectedResource = $this->getMCPBaseUrl($request);

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
        $logFile = '/var/www/devsa/logs/uncaught.log'; // Define the variable properly

        $oauthBaseUrl = $this->getOAuthBaseUrl($request);
        $mcpResourceUrl = $this->getMCPBaseUrl($request) . $request->getUri()->getPath();


        file_put_contents($logFile, "[OAUTH-DISCOVERY] OAuth Base URL: {$oauthBaseUrl}\n", FILE_APPEND);
        file_put_contents($logFile, "[OAUTH-DISCOVERY] MCP Resource URL: {$mcpResourceUrl}\n", FILE_APPEND);

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

        $wellknownEndpoints = $this->config['oauth']['wellknown'];

        $responseData['error']['data']['oauth']['resource'] = $mcpResourceUrl;
        $responseData['error']['data']['oauth']['resource_metadata_endpoint'] = $oauthBaseUrl . $wellknownEndpoints['protected_resource'];
        $responseData['error']['data']['oauth']['authorization_server_metadata_endpoint'] = $oauthBaseUrl . $wellknownEndpoints['auth_server'];

        $jsonContent = json_encode($responseData);
        if ($jsonContent === false) {
            $jsonContent = '{"jsonrpc":"2.0","error":{"code":-32000,"message":"JSON encoding error"},"id":null}';
        }

        file_put_contents($logFile, "[OAUTH-DISCOVERY] Response JSON: {$jsonContent}\n", FILE_APPEND);

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

        file_put_contents($logFile, "[OAUTH-DISCOVERY] WWW-Authenticate: Bearer realm=\"MCP Server\", resource_metadata=\"{$resourceMetadataUrl}\"\n", FILE_APPEND);

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

        return 'https://' . $host . ($uri->getPort() ? ':' . $uri->getPort() : '');
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
                'wellknown' => [
                    'auth_server' => '/.well-known/oauth-authorization-server',
                    'protected_resource' => '/.well-known/oauth-protected-resource'
                ],
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
