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
    private mixed $requestId = null;

    /**
     * Initialize the MCP SaaS Server
     *
     * @param StorageInterface $storage Database or memory storage implementation
     * @param ToolRegistry $toolRegistry Registry for managing MCP tools
     * @param PromptRegistry $promptRegistry Registry for managing MCP prompts
     * @param ResourceRegistry $resourceRegistry Registry for managing MCP resources
     * @param array $config Server configuration array
     * @param LoggerInterface|null $logger Optional logger instance
     */
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
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();

        $this->versionNegotiator = new VersionNegotiator($this->config);
        $this->messageHandler = new MessageHandler($this->toolRegistry, $this->promptRegistry, $this->resourceRegistry, $this->storage, $this->config);
        $this->sseTransport = new SSETransport($this->storage, $this->config);
        $this->streamableTransport = new StreamableHTTPTransport($this->storage, $this->config, $this->logger);
    }

    /**
     * Main MCP endpoint handler for processing HTTP requests
     *
     * @param Request $request PSR-7 HTTP request
     * @param Response $response PSR-7 HTTP response
     * @return Response Modified PSR-7 response with MCP data or stream
     * @throws AuthenticationException When authentication is required but missing/invalid
     * @throws ProtocolException When protocol violations occur
     */
    public function handle(Request $request, Response $response): Response
    {
        try {
            $this->contextData = $request->getAttribute('mcp_context') ?? [];
            $isAuthless = $this->config['auth']['authless'];

            if ($request->getMethod() === 'OPTIONS') {
                return $this->handleCorsPreflightRequest($response);
            }

            if (!$this->isOriginAllowed($request)) {
                return $this->createErrorResponse(
                    $response,
                    -32600,
                    'Origin not allowed. This server refuses cross-origin browser requests to a loopback address.',
                    null,
                    403
                );
            }

            if (!$this->acceptsContentType($request, $request->getMethod() === 'GET' ? 'text/event-stream' : 'application/json')) {
                return $this->createErrorResponse(
                    $response,
                    -32600,
                    $request->getMethod() === 'GET'
                        ? 'This endpoint answers GET with text/event-stream. Send Accept: text/event-stream.'
                        : 'This endpoint answers POST with application/json. Send Accept: application/json, text/event-stream.',
                    null,
                    406
                );
            }

            if ($request->getMethod() === 'POST') {

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

                    if ($data !== null && !is_array($data)) {
                        throw new ProtocolException('Request must be JSON object', -32600);
                    }
                }

                $this->requestId = $data['id'] ?? null;

                $isInitialize = ($data['method'] ?? '') === 'initialize';

                if (!$isInitialize && empty($this->contextData) && !$isAuthless) {
                    throw new AuthenticationException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Authentication required');
                }

                $this->sessionId = $this->negotiateSessionId($request, $data);

                if ($isInitialize) {

                    $clientProtocolVersion = $data['params']['protocolVersion'] ?? null;
                    if (!$clientProtocolVersion) {
                        throw new ProtocolException('Invalid params: protocolVersion required. Send the protocol version your client speaks, for example ' . $this->config['supported_versions'][0] . '.', -32602);
                    }

                    $protocolVersion = $this->versionNegotiator->negotiate($clientProtocolVersion);

                    $openedSessionId = $this->extractSessionIdFromRequest($request);

                    if ($openedSessionId
                        && str_starts_with($openedSessionId, $protocolVersion . '_')
                        && $this->storage->getSession($openedSessionId)) {
                        $this->sessionId = $openedSessionId;
                    } else {
                        $this->sessionId = $protocolVersion . '_' . $this->sessionId;
                    }

                    return $this->messageHandler->handleInitialize($data['params'] ?? [], $data['id'] ?? null, $this->sessionId, $protocolVersion, $response);
                }

                return $this->handleMCPRequest($request, $response, $data);
            }

            if ($request->getMethod() === 'GET') {

                if ($isAuthless && empty($this->contextData)) {
                    $this->contextData = $this->getDefaultAuthlessContext($request);
                }

                if (empty($this->contextData) && !$isAuthless) {
                    throw new AuthenticationException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Authentication required');
                }

                $this->sessionId = $this->negotiateSessionId($request);
                $protocolVersion = $this->getSessionProtocolVersion($request);
                return $this->handleStreamConnection($request, $response, $protocolVersion);
            }

            if ($request->getMethod() === 'DELETE') {
                $sessionId = $this->extractSessionIdFromRequest($request);

                if ($sessionId) {
                    $this->storage->storeSession($sessionId, [], 0);
                }

                return $response
                    ->withHeader('Access-Control-Allow-Origin', '*')
                    ->withStatus(204);
            }

            return $this->createErrorResponse(
                $response,
                -32600,
                'Method not allowed. This endpoint accepts POST for requests, GET for the event stream, DELETE to end a session and OPTIONS for preflight.',
                null,
                405
            );
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
                'message' => $e->getMessage(),
                'session_id' => $this->sessionId
                ]
            );

            $httpStatus = $e->getCode() === -32001 ? 404 : 400;

            return $this->createErrorResponse($response, $e->getCode(), $e->getMessage(), $this->requestId, $httpStatus);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'Unexpected error in MCP handler',
                [
                'message' => $e->getMessage()
                ]
            );
            return $this->createErrorResponse($response, -32603, 'Internal error', $this->requestId, 500);
        }
    }

    /**
     * Get default authless context when none is provided
     */
    private function getDefaultAuthlessContext(Request $request): array
    {
        return [
            'context_data' => [
                'id' => 1,
                'uuid' => 'authless-public',
                'name' => 'Authless Public Access',
                'active' => true,
                'context_type' => 'public'
            ],
            'token_data' => [
                'user_id' => 1,
                'scope' => 'mcp:read mcp:write',
                'access_token' => 'authless-access',
                'expires_at' => time() + 86400
            ],
            'context_id' => 'authless-public',
            'base_url' => $this->getBaseUrl($request),
            'authless' => true
        ];
    }

    /**
     * Session ID negotiation for MCP protocol
     */
    private function negotiateSessionId(Request $request, ?array $data = null): ?string
    {
        $method = $request->getMethod();

        $existingSessionId = $this->extractSessionIdFromRequest($request);

        if ($method === 'GET') {
            if (!$existingSessionId) {
                $newSessionId = '2024-11-05_' . $this->generateSessionId();

                $this->storage->storeSession(
                    $newSessionId,
                    ['protocol_version' => '2024-11-05', 'created_at' => time()],
                    (int)$this->config['session_lifetime']
                );

                return $newSessionId;
            }

            $sessionData = $this->storage->getSession($existingSessionId);
            if (!$sessionData) {
                throw new ProtocolException('Invalid or expired session ID. Send a new initialize request to start a session.', -32001);
            }

            return $existingSessionId;
        }

        if ($method === 'POST') {

            if (($data['method'] ?? '') === 'initialize') {

                $newSessionId = $this->generateSessionId();
                return $newSessionId;
            }

            if (!$existingSessionId) {
                $this->logger->warning('No session ID found in request', [
                    'method' => $data['method'] ?? 'unknown',
                    'headers' => array_keys($request->getHeaders())
                ]);
                throw new ProtocolException('Session ID required. Send an initialize request first, then repeat its Mcp-Session-Id response header on every later request.', -32001);
            }

            $sessionData = $this->storage->getSession($existingSessionId);
            if (!$sessionData) {
                $this->logger->warning('Session not found in storage', [
                    'session_id' => $existingSessionId,
                    'method' => $data['method'] ?? 'unknown'
                ]);
                throw new ProtocolException('Invalid or expired session ID. Send a new initialize request to start a session.', -32001);
            }

            return $existingSessionId;
        }

        return null;
    }

    /**
     * Extract session ID from request headers or route parameters
     */
    private function extractSessionIdFromRequest(Request $request): ?string
    {
        $sessID = $request->getHeaderLine('mcp-session-id');
        if (!empty($sessID)) {
            return $sessID;
        }

        foreach ($request->getHeaders() as $name => $values) {
            $lowerName = strtolower($name);

            if ($lowerName === 'mcp-session-id') {
                return $values[0];
            }
        }

        $route = $request->getAttribute('__route__');

        if ($route && method_exists($route, 'getArgument')) {
            $routeSessionId = $route->getArgument('sessID');

            if ($routeSessionId) {
                return $routeSessionId;
            }
        }

        $path = $request->getUri()->getPath();

        $pathSegments = explode('/', trim($path, '/'));

        foreach ($pathSegments as $index => $segment) {

            if (preg_match('/^[a-zA-Z0-9.-]+_[a-zA-Z0-9]+$/', $segment)) {
                return $segment;
            }
        }
        return null;
    }

    /**
     * Get base URL from request with fallback
     */
    private function getBaseUrl(Request $request): string
    {

        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        $uri = $request->getUri();
        $scheme = 'https';
        $host = $uri->getHost() ?: 'localhost';
        $port = $uri->getPort();

        $baseUrl = $scheme . '://' . $host;
        if (is_numeric($port) && $scheme === 'https' && $port !== 443) {
            $baseUrl .= ':' . $port;
        }

        return $baseUrl;
    }

    /**
     * Get protocol version for streaming connections (GET requests)
     * Extract from the stored session or parse from sessionId
     */
    private function getSessionProtocolVersion(Request $request): string
    {

        $sessionData = $this->storage->getSession($this->sessionId);
        if (!$sessionData || !isset($sessionData['protocol_version'])) {

            if ($this->sessionId && strpos($this->sessionId, '_') !== false) {
                $parts = explode('_', $this->sessionId, 2);
                if (count($parts) === 2) {
                    $protocolFromSessionId = $parts[0];

                    if (in_array($protocolFromSessionId, $this->config['supported_versions'])) {
                        return $protocolFromSessionId;
                    }
                }
            }
            throw new ProtocolException('No protocol version found in session', -32001);
        }

        $negotiatedVersion = $sessionData['protocol_version'];

        if (strcmp($negotiatedVersion, '2025-06-18') >= 0) {
            $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');

            if ($headerVersion && $headerVersion !== $negotiatedVersion) {
                throw new ProtocolException(
                    "MCP-Protocol-Version header says {$headerVersion} but this session negotiated {$negotiatedVersion}. Send MCP-Protocol-Version: {$negotiatedVersion} or start a new session with initialize.",
                    -32600
                );
            }
        }

        return $negotiatedVersion;
    }

    /**
     * Determine which transport to use based on protocol version
     */
    private function shouldUseStreamableHTTP(string $protocolVersion): bool
    {
        return strcmp($protocolVersion, '2025-03-26') >= 0;
    }

    /**
     * Handle streaming connection using appropriate transport based on protocol version
     */
    private function handleStreamConnection(Request $request, Response $response, string $protocolVersion): Response
    {
        if ($this->shouldUseStreamableHTTP($protocolVersion)) {
            try {
                $streamableResponse = $this->streamableTransport->handleConnection(
                    $request,
                    $response,
                    $this->sessionId,
                    array_replace_recursive($this->contextData, ['protocol_version' => $protocolVersion])
                );
                return $streamableResponse;
            } catch (\Throwable $e) {
                $this->logger->error('MCPSaaSServer transport exception', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
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
            throw new ProtocolException('No request data provided', -32600);
        }

        return $this->messageHandler->processMessage(
            $data,
            $this->sessionId,
            $this->contextData,
            $response
        );
    }

    /**
     * Check whether the client accepts the content type this endpoint will return
     *
     * @param string $contentType the type the endpoint produces
     * @return bool false when the client asked for something else entirely
     */
    private function acceptsContentType(Request $request, string $contentType): bool
    {
        $accept = $request->getHeaderLine('Accept');

        if ($accept === '') {
            return true;
        }

        [$group] = explode('/', $contentType);

        foreach ([$contentType, $group . '/*', '*/*'] as $candidate) {
            if (stripos($accept, $candidate) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check the Origin header
     *
     * @return bool false when the request must be refused with 403
     */
    private function isOriginAllowed(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');

        if (empty($origin)) {
            return true;
        }

        $allowedOrigins = $this->config['auth']['allowed_origins'];

        if (!empty($allowedOrigins)) {
            return in_array($origin, $allowedOrigins, true);
        }

        $hostOnly = explode(':', $request->getHeaderLine('Host'))[0];
        $originHost = parse_url($origin, PHP_URL_HOST) ?? '';

        $localhostHosts = ['localhost', '127.0.0.1', '::1'];

        return !in_array($hostOnly, $localhostHosts) || in_array($originHost, $localhostHosts);
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
     * Register a tool with the MCP server
     *
     * @param string $name Unique tool name identifier
     * @param callable $handler Function that executes the tool logic
     * @param array $schema JSON schema defining tool parameters and metadata
     * @return void
     */
    public function addTool(string $name, callable $handler, array $schema = []): void
    {
        $this->toolRegistry->register($name, $handler, $schema);
    }

    /**
     * Register a prompt with the MCP server
     *
     * @param string $name Unique prompt name identifier
     * @param callable $handler Function that generates the prompt content
     * @param array $schema JSON schema defining prompt arguments and metadata
     * @return void
     */
    public function addPrompt(string $name, callable $handler, array $schema = []): void
    {
        $this->promptRegistry->register($name, $handler, $schema);
    }

    /**
     * Register a resource with the MCP server
     *
     * @param string $uri Unique resource URI identifier
     * @param callable $handler Function that provides the resource content
     * @param array $schema JSON schema defining resource metadata
     * @return void
     */
    public function addResource(string $uri, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->register($uri, $handler, $schema);
    }

    /**
     * Register a resource template with the MCP server
     *
     * @param string $uriTemplate URI pattern with variables in {curly} braces
     * @param callable $handler Function that provides the resource content
     * @param array $schema JSON schema defining resource template metadata
     * @return void
     */
    public function addResourceTemplate(string $uriTemplate, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->registerTemplate($uriTemplate, $handler, $schema);
    }

    /**
     * Send a progress notification for the request being handled
     *
     * @param string $sessionId Session the request arrived on
     * @param int|float $progress Work completed so far
     * @param string $message Human readable step description (2025-03-26+)
     * @param int|float|null $total Expected total when known
     * @return void
     */
    public function sendProgressNotification(string $sessionId, int|float $progress, string $message = '', int|float|null $total = null): void
    {
        $this->messageHandler->sendProgressNotification($sessionId, $progress, $message, $total);
    }

    /**
     * Send a list_changed notification
     *
     * @param string $sessionId Session to notify
     * @param string $listType One of tools, prompts or resources
     * @return void
     */
    public function notifyListChanged(string $sessionId, string $listType): void
    {
        $this->messageHandler->sendListChangedNotification($sessionId, $listType);
    }

    /**
     * Send a resources/updated notification to a subscribed session
     *
     * @param string $sessionId Session to notify
     * @param string $uri Resource URI that changed
     * @return void
     */
    public function notifyResourceUpdated(string $sessionId, string $uri): void
    {
        $this->messageHandler->sendResourceUpdatedNotification($sessionId, $uri);
    }

    /**
     * Send a notifications/message log record
     *
     * @param string $sessionId Session to notify
     * @param string $level RFC 5424 severity
     * @param mixed $data Log payload
     * @param string $logger Optional logger name
     * @return void
     */
    public function sendLogMessage(string $sessionId, string $level, mixed $data, string $logger = ''): void
    {
        $this->messageHandler->sendLogMessage($sessionId, $level, $data, $logger);
    }

    /**
     * Ask the client's model to generate a completion
     *
     * @param string $sessionId Session to send the request on
     * @param array $messages Conversation to sample from
     * @param array $options maxTokens, temperature, stopSequences, systemPrompt, modelPreferences, and tools/toolChoice from 2025-11-25
     * @param array $context Context stored alongside the queued request
     * @return string Request id the client's response will carry
     */
    public function requestSampling(string $sessionId, array $messages, array $options = [], array $context = []): string
    {
        return $this->messageHandler->requestSampling($sessionId, $messages, $options, $context);
    }

    /**
     * Ask the client's user for structured input
     *
     * @param string $sessionId Session to send the request on
     * @param string $message Prompt shown to the user
     * @param array|null $requestedSchema Schema describing the fields requested
     * @param array $context Context stored alongside the queued request
     * @param array $options Pass a 'url' to use URL mode elicitation (2025-11-25)
     * @return string Request id the client's response will carry
     */
    public function requestElicitation(string $sessionId, string $message, ?array $requestedSchema = null, array $context = [], array $options = []): string
    {
        return $this->messageHandler->requestElicitation($sessionId, $message, $requestedSchema, $context, $options);
    }

    /**
     * Ask the client which roots it exposes
     *
     * @param string $sessionId Session to send the request on
     * @param array $context Context stored alongside the queued request
     * @return string Request id the client's response will carry
     */
    public function requestRootsList(string $sessionId, array $context = []): string
    {
        return $this->messageHandler->requestRootsList($sessionId, $context);
    }

    /**
     * Set the current authentication and context data
     *
     * @param array $contextData Context information including user, agency, and token data
     * @return void
     */
    public function setContext(array $contextData): void
    {
        $this->contextData = $contextData;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [

            'supported_versions' => ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'],
            'base_url' => null,
            'session_user_id' => null,
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'session_lifetime' => 3600,
            'pagination' => [
                'page_size' => 50
            ],
            'tasks' => [
                'default_ttl' => 300000,
                'max_ttl' => 3600000,
                'poll_interval' => 1000,
                'max_retained' => 50
            ],
            'test_mode' => false,
            'server_info' => [
                'name' => 'WaaSuP MCP SaaS Server',
                'version' => '3.0.0'
            ],
            'auth' => [
                'context_types' => ['agency', 'user'],
                'validate_scope' => true,
                'required_scopes' => ['mcp:read'],
                'authless' => false,
                'allowed_origins' => [],
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
                'access_token_lifetime' => 3600,
                'refresh_token_lifetime' => null,
                'authorization_code_lifetime' => 300,
                'sliding_expiration' => false,
                'sliding_expiration_max_lifetime' => null,
                'sliding_expiration_interval' => 60,
                'ui' => [
                    'background_color' => null,
                    'text_color' => null,
                    'accent_color' => null
                ],
                'auth_server' => [
                    'endpoints' => [
                        'authorize' => '/oauth/authorize',
                        'token' => '/oauth/token',
                        'verify' => '/oauth/verify',
                        'consent' => '/oauth/consent',
                        'register' => '/oauth/register',
                        'revoke' => '/oauth/revoke'
                    ],
                    'providers' => [
                        'google' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ],
                        'linkedin' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ],
                        'github' => [
                            'client_id' => null,
                            'client_secret' => null,
                            'redirect_uri' => null
                        ]
                    ]
                ],
                'resource_server' => [
                    'enabled' => true,
                    'resource_indicators_supported' => true,
                    'resource_indicator' => null,
                    'metadata_enabled' => true,
                    'require_resource_binding' => true
                ]
            ],
            'sse' => [
                'keepalive_interval' => 1,
                'max_connection_time' => 1800,
                'switch_interval_after' => 60
            ],
            'streamable_http' => [
                'keepalive_interval' => 1,
                'max_connection_time' => 1800,
                'switch_interval_after' => 60
            ],
            'database' => [
                'table_prefix' => 'mcp_',
                'cleanup_interval' => 3600,
                'message_lifetime' => 3600,
                'table_mapping' => []
            ]
        ];
    }
}
