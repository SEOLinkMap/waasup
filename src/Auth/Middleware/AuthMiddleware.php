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
 * Generic OAuth authentication middleware for MCP
 */
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
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        try {
            // Extract context identifier from route (agency UUID, user ID, etc.)
            $contextId = $this->extractContextId($request);

            if (!$contextId) {
                throw new AuthenticationException('Missing context identifier');
            }

            // Validate context (agency, user, etc.)
            $contextData = $this->validateContext($contextId);

            if (!$contextData) {
                throw new AuthenticationException('Invalid or inactive context');
            }

            // Extract and validate OAuth token
            $accessToken = $this->extractAccessToken($request);

            if (!$accessToken) {
                return $this->createOAuthDiscoveryResponse();
            }

            $tokenData = $this->validateToken($accessToken, $contextData);

            if (!$tokenData) {
                return $this->createOAuthDiscoveryResponse();
            }

            // Add context data to request
            $request = $request->withAttribute(
                'mcp_context',
                [
                'context_data' => $contextData,
                'token_data' => $tokenData,
                'context_id' => $contextId,
                'base_url' => $this->getBaseUrl($request)
                ]
            );

            return $handler->handle($request);
        } catch (AuthenticationException $e) {
            return $this->createOAuthDiscoveryResponse();
        } catch (\Exception $e) {
            return $this->createErrorResponse('Internal authentication error', 500);
        }
    }

    /**
     * Extract context identifier from request
     * Override this method for custom routing schemes
     */
    protected function extractContextId(Request $request): ?string
    {
        $route = $request->getAttribute('__route__');

        if ($route && method_exists($route, 'getArgument')) {
            // Try common parameter names
            $contextId = $route->getArgument('agencyUuid') ??
                        $route->getArgument('userId') ??
                        $route->getArgument('contextId');

            if ($contextId) {
                return $contextId;
            }
        }

        // Fallback to path parsing
        $path = $request->getUri()->getPath();
        $segments = explode('/', trim($path, '/'));

        // Look for UUID-like strings in path
        foreach ($segments as $segment) {
            if ($this->isValidUuid($segment)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Validate context data
     */
    protected function validateContext(string $contextId): ?array
    {
        // Try different context types
        $contextTypes = $this->config['context_types'] ?? ['agency', 'user'];

        foreach ($contextTypes as $type) {
            $contextData = $this->storage->getContextData($contextId, $type);
            if ($contextData) {
                $contextData['context_type'] = $type;
                return $contextData;
            }
        }

        return null;
    }

    /**
     * Extract Bearer token from Authorization header
     */
    protected function extractAccessToken(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Validate OAuth token
     */
    protected function validateToken(string $accessToken, array $contextData): ?array
    {
        $tokenData = $this->storage->validateToken($accessToken, $contextData);

        if (!$tokenData) {
            return null;
        }

        // Additional validation can be added here
        if ($this->config['validate_scope'] && isset($tokenData['scope'])) {
            $requiredScopes = $this->config['required_scopes'] ?? [];
            $tokenScopes = explode(' ', $tokenData['scope']);

            foreach ($requiredScopes as $requiredScope) {
                if (!in_array($requiredScope, $tokenScopes)) {
                    return null;
                }
            }
        }

        return $tokenData;
    }

    /**
     * Create OAuth discovery response
     */
    protected function createOAuthDiscoveryResponse(): Response
    {
        $baseUrl = $this->config['base_url'] ?? 'https://localhost';

        $responseData = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => 'Authentication required',
                'data' => [
                    'oauth' => [
                        'authorization_endpoint' => "{$baseUrl}/oauth/authorize",
                        'token_endpoint' => "{$baseUrl}/oauth/token",
                        'registration_endpoint' => "{$baseUrl}/oauth/register"
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

        return $this->responseFactory->createResponse(401)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="MCP Server"')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * Create generic error response
     */
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

    /**
     * Validate UUID format
     */
    protected function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * Get base URL from request
     */
    protected function getBaseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() .
               ($uri->getPort() ? ':' . $uri->getPort() : '');
    }

    /**
     * Get default configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'context_types' => ['agency', 'user'],
            'validate_scope' => false,
            'required_scopes' => [],
            'base_url' => 'https://localhost'
        ];
    }
}
