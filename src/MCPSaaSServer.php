<?php

namespace Seolinkmap\Waasup;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Seolinkmap\Waasup\Exception\AuthenticationException;
use Seolinkmap\Waasup\Exception\ProtocolException;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Protocol\MessageHandler;
use Seolinkmap\Waasup\Protocol\VersionNegotiator;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Storage\StorageInterface;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Transport\SSETransport;
use Seolinkmap\Waasup\Transport\StreamableHTTPTransport;

class MCPSaaSServer
{
    private StorageInterface $storage;
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private VersionNegotiator $versionNegotiator;
    private MessageHandler $messageHandler;
    private SSETransport $sseTransport;
    private StreamableHTTPTransport $streamableTransport;
    private LoggerInterface $logger;
    private array $config;
    private ?array $contextData = null;
    private ?string $sessionId = null;

    public function __construct(
        StorageInterface $storage,
        ToolRegistry $toolRegistry,
        PromptRegistry $promptRegistry,
        ResourceRegistry $resourceRegistry,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->storage = $storage;
        $this->toolRegistry = $toolRegistry;
        $this->promptRegistry = $promptRegistry;
        $this->resourceRegistry = $resourceRegistry;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();

        $this->versionNegotiator = new VersionNegotiator($this->config['supported_versions']);
        $this->messageHandler = new MessageHandler($this->toolRegistry, $this->promptRegistry, $this->resourceRegistry, $this->storage, $this->config, $this->versionNegotiator);
        $this->sseTransport = new SSETransport($this->storage, $this->config['sse']);
        $this->streamableTransport = new StreamableHTTPTransport($this->storage, $this->config['streamable_http']);
    }

    /**
     * Main MCP endpoint handler
     */
    public function handle(Request $request, Response $response): Response
    {
        try {
            $this->contextData = $request->getAttribute('mcp_context') ?? [];

            if ($request->getMethod() === 'OPTIONS') {
                return $this->handleCorsPreflightRequest($response);
            }

            $this->validateOriginHeader($request);

            if ($request->getMethod() === 'POST') {
                // Parse JSON FIRST to catch parse errors
                $body = (string) $request->getBody();
                $data = null;

                if (!empty($body)) {
                    $data = json_decode($body, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->error(
                            'JSON-RPC processing error',
                            [
                            'error' => json_last_error_msg()
                            ]
                        );
                        throw new ProtocolException('Parse error', -32700);
                    }
                }

                // THEN check authentication
                if (empty($this->contextData)) {
                    throw new AuthenticationException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Authentication required');
                }

                $this->sessionId = $this->negotiateSessionId($request, $data);

                // Extract and store protocol version for initialize requests
                if (($data['method'] ?? '') === 'initialize') {
                    $protocolVersion = $this->extractProtocolFromInitialize($request);
                    if ($protocolVersion && $this->sessionId) {
                        $this->storage->storeSession($this->sessionId, ['protocol_version' => $protocolVersion]);
                    }
                }

                return $this->handleMCPRequest($request, $response, $data);
            }

            if ($request->getMethod() === 'GET') {
                if (empty($this->contextData)) {
                    throw new AuthenticationException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Authentication required');
                }
                $this->sessionId = $this->negotiateSessionId($request);
                $protocolVersion = $this->getSessionProtocolVersion($request);
                return $this->handleStreamConnection($request, $response, $protocolVersion);
            }

            throw new ProtocolException('Method not allowed', -32002);
        } catch (AuthenticationException $e) {
            $this->logger->warning(
                'Authentication failed',
                [
                'message' => $e->getMessage(),
                'session_id' => $this->sessionId
                ]
            );
            return $this->createErrorResponse($response, -32000, 'Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT.', null, 401);
        } catch (ProtocolException $e) {
            $this->logger->error(
                'Protocol error',
                [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
                ]
            );
            return $this->createErrorResponse($response, $e->getCode(), 'Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT.');
        } catch (\Exception $e) {
            $this->logger->critical(
                'Unexpected error in MCP handler',
                [
                'message' => $e->getMessage()
                ]
            );
            return $this->createErrorResponse($response, -32603, 'Internal error');
        }
    }

    /**
     * Get protocol version for streaming connections (GET requests)
     * Spec-compliant: only check headers for versions that require them
     */
    private function getSessionProtocolVersion(Request $request): string
    {
        // First get the negotiated version from session
        $sessionData = $this->storage->getSession($this->sessionId);
        $negotiatedVersion = $sessionData['protocol_version'] ?? '2024-11-05';

        // Only check MCP-Protocol-Version header for 2025-06-18 (spec requirement)
        if ($negotiatedVersion === '2025-06-18') {
            $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');
            if (!$headerVersion) {
                throw new ProtocolException('MCP-Protocol-Version header required for version 2025-06-18', -32600);
            }
            if ($headerVersion !== $negotiatedVersion) {
                throw new ProtocolException('MCP-Protocol-Version header must match negotiated version', -32600);
            }
        }

        return $negotiatedVersion;
    }

    /**
     * Extract protocol version from initialize message (POST requests only)
     */
    private function extractProtocolFromInitialize(Request $request): ?string
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $body = (string) $request->getBody();
        if (!empty($body)) {
            $data = json_decode($body, true);
            if (isset($data['method']) && $data['method'] === 'initialize' && isset($data['params']['protocolVersion'])) {
                return $data['params']['protocolVersion'];
            }
        }

        return null;
    }

    /**
     * Determine which transport to use based on protocol version
     */
    private function shouldUseStreamableHTTP(string $protocolVersion): bool
    {
        return in_array($protocolVersion, ['2025-03-26', '2025-06-18']);
    }

    /**
     * Session ID negotiation per MCP 2024 HTTP+SSE specification
     */
    private function negotiateSessionId(Request $request, ?array $data = null): ?string
    {
        $method = $request->getMethod();

        // Check for existing session ID in header
        $existingSessionId = $this->extractSessionIdFromRequest($request);

        if ($method === 'GET') {
            // GET requires existing session ID
            if (!$existingSessionId) {
                throw new ProtocolException('Session ID required for GET requests', -32001);
            }
            return $existingSessionId;
        }

        if ($method === 'POST') {
            // Return existing session if available
            if ($existingSessionId) {
                return $existingSessionId;
            }

            // Check if this is an initialize request
            $body = (string) $request->getBody();
            $data = json_decode($body, true);

            if (($data['method'] ?? '') === 'initialize') {
                $newSessionId = $this->generateSessionId();
                $this->logger->info(
                    'New MCP session created',
                    [
                    'session_id' => $newSessionId,
                    'context' => $this->contextData
                    ]
                );
                return $newSessionId;
            }

            // Non-initialize POST without session
            throw new ProtocolException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Session required', -32001);
        }

        return null;
    }

    /**
     * Handle streaming connection using appropriate transport based on protocol version
     */
    private function handleStreamConnection(Request $request, Response $response, string $protocolVersion): Response
    {
        $this->logger->info(
            'Stream connection established',
            [
            'session_id' => $this->sessionId,
            'protocol_version' => $protocolVersion,
            'transport' => $this->shouldUseStreamableHTTP($protocolVersion) ? 'streamable_http' : 'sse',
            'context' => $this->contextData
            ]
        );

        if ($this->shouldUseStreamableHTTP($protocolVersion)) {
            return $this->streamableTransport->handleConnection(
                $request,
                $response,
                $this->sessionId,
                array_merge($this->contextData, ['protocol_version' => $protocolVersion])
            );
        } else {
            return $this->sseTransport->handleConnection(
                $request,
                $response,
                $this->sessionId,
                $this->contextData
            );
        }
    }

    /**
     * Handle MCP JSON-RPC requests
     */
    private function handleMCPRequest(Request $request, Response $response, ?array $data = null): Response
    {
        if ($data === null) {
            $body = (string) $request->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ProtocolException('Parse error', -32700);
            }
        }

        $this->logger->debug(
            'Processing MCP request',
            [
            'method' => $data['method'] ?? 'unknown',
            'session_id' => $this->sessionId
            ]
        );

        return $this->messageHandler->processMessage(
            $data,
            $this->sessionId,
            $this->contextData,
            $response
        );
    }

    /**
     * Validate Origin header for 2025-03-26+ versions to prevent DNS rebinding attacks
     */
    private function validateOriginHeader(Request $request): void
    {
        $origin = $request->getHeaderLine('Origin');
        $host = $request->getHeaderLine('Host');

        // Allow requests without Origin header (non-browser clients)
        if (empty($origin)) {
            return;
        }

        // DNS rebinding protection: reject external origins trying to access localhost
        $hostOnly = explode(':', $host)[0]; // Remove port
        $originHost = parse_url($origin, PHP_URL_HOST) ?? '';

        $localhostHosts = ['localhost', '127.0.0.1', '::1'];

        if (in_array($hostOnly, $localhostHosts) && !in_array($originHost, $localhostHosts)) {
            throw new ProtocolException('DNS rebinding attack detected', -32600);
        }
    }

    /**
     * Handle CORS preflight requests
     */
    private function handleCorsPreflightRequest(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Max-Age', '3600')
            ->withStatus(200);
    }

    /**
     * Extract session ID from request headers or route
     */
    private function extractSessionIdFromRequest(Request $request): ?string
    {
        // Check headers first (case-insensitive)
        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) === 'mcp-session-id') {
                return $values[0];
            }
        }

        // Check route parameters as fallback
        $route = $request->getAttribute('__route__');
        if ($route && method_exists($route, 'getArgument')) {
            $routeSessionId = $route->getArgument('sessID');
            if ($routeSessionId) {
                return $routeSessionId;
            }
        }

        return null;
    }

    /**
     * Generate a new session ID
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Create standardized error response
     */
    private function createErrorResponse(
        Response $response,
        int $code,
        string $message,
        mixed $id = null,
        int $httpStatus = 400
    ): Response {
        $errorResponse = [
            'jsonrpc' => '2.0',
            'error' => ['code' => $code, 'message' => $message],
            'id' => $id
        ];

        $response->getBody()->write(json_encode($errorResponse));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version')
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->withStatus($httpStatus);
    }

    /**
     * Add a tool to the registry
     */
    public function addTool(string $name, callable $handler, array $schema = []): void
    {
        $this->toolRegistry->register($name, $handler, $schema);
    }

    /**
     * Add a prompt to the registry
     */
    public function addPrompt(string $name, callable $handler, array $schema = []): void
    {
        $this->promptRegistry->register($name, $handler, $schema);
    }

    /**
     * Add a resource to the registry
     */
    public function addResource(string $uri, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->register($uri, $handler, $schema);
    }

    /**
     * Add a resource template to the registry
     */
    public function addResourceTemplate(string $uriTemplate, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->registerTemplate($uriTemplate, $handler, $schema);
    }

    /**
     * Set context data (typically from middleware)
     */
    public function setContext(array $contextData): void
    {
        $this->contextData = $contextData;
    }

    /**
     * Request LLM sampling from connected client
     */
    public function requestSampling(
        string $sessionId,
        array $messages,
        array $options = []
    ): string {
        return $this->messageHandler->requestSampling(
            $sessionId,
            $messages,
            $options,
            $this->contextData ?? []
        );
    }

    /**
     * Get sampling response by request ID
     */
    public function getSamplingResponse(string $sessionId, string $requestId): ?array
    {
        return $this->storage->getSamplingResponse($sessionId, $requestId);
    }

    /**
     * Get all sampling responses for session
     */
    public function getSamplingResponses(string $sessionId): array
    {
        return $this->storage->getSamplingResponses($sessionId);
    }

    /**
     * Request list of available filesystem roots from client
     */
    public function requestRootsList(string $sessionId): string
    {
        return $this->messageHandler->requestRootsList(
            $sessionId,
            $this->contextData ?? []
        );
    }

    /**
     * Request file/directory read from client filesystem
     */
    public function requestRootsRead(
        string $sessionId,
        string $uri,
        array $options = []
    ): string {
        return $this->messageHandler->requestRootsRead(
            $sessionId,
            $uri,
            $options,
            $this->contextData ?? []
        );
    }

    /**
     * Request directory listing from client filesystem
     */
    public function requestRootsListDirectory(
        string $sessionId,
        string $uri,
        array $options = []
    ): string {
        return $this->messageHandler->requestRootsListDirectory(
            $sessionId,
            $uri,
            $options,
            $this->contextData ?? []
        );
    }

    /**
     * Get roots response by request ID
     */
    public function getRootsResponse(string $sessionId, string $requestId): ?array
    {
        return $this->storage->getRootsResponse($sessionId, $requestId);
    }

    /**
     * Get all roots responses for session
     */
    public function getRootsResponses(string $sessionId): array
    {
        return $this->storage->getRootsResponses($sessionId);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
        'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
        'server_info' => [
            'name' => 'WaaSuP MCP SaaS Server',
            'version' => '1.0.0'
        ],
        'sse' => [
            'keepalive_interval' => 1,
            'max_connection_time' => 1800,
            'switch_interval_after' => 60
        ],
        'streamable_http' => [
            'keepalive_interval' => 2,
            'max_connection_time' => 1800,
            'switch_interval_after' => 60
        ],
        'oauth' => [
            'resource_server' => true,
            'resource_indicators_supported' => true,
            'resource_indicator' => null
        ]
        ];
    }
}
