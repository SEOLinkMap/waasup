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
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();

        $this->versionNegotiator = new VersionNegotiator($this->config);
        $this->messageHandler = new MessageHandler($this->toolRegistry, $this->promptRegistry, $this->resourceRegistry, $this->storage, $this->config, $this->versionNegotiator);
        $this->sseTransport = new SSETransport($this->storage, $this->config);
        $this->streamableTransport = new StreamableHTTPTransport($this->storage, $this->config, $this->logger);
    }

    /**
     * Main MCP endpoint handler
     */
    public function handle(Request $request, Response $response): Response
    {
        $logFile = '/var/www/devsa/logs/uncaught.log';
        file_put_contents($logFile, "[MCP-SERVER] Headers received:\n", FILE_APPEND);
        foreach ($request->getHeaders() as $name => $values) {
            file_put_contents($logFile, "[MCP-SERVER] '$name' = '" . implode(', ', $values) . "'\n", FILE_APPEND);
        }
        try {
            $this->contextData = $request->getAttribute('mcp_context') ?? [];
            $isAuthless = $this->config['auth']['authless'];
            $this->logger->info("DEBUG authless check", [
                'isAuthless' => $isAuthless,
                'contextData_empty' => empty($this->contextData),
                'auth_config' => $this->config['auth']
            ]);

            if ($request->getMethod() === 'OPTIONS') {
                return $this->handleCorsPreflightRequest($response);
            }

            $this->validateOriginHeader($request);

            if ($request->getMethod() === 'POST') {
                // Parse JSON FIRST to catch parse errors and avoid multiple body reads
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

                    // Ensure we have an array
                    if ($data !== null && !is_array($data)) {
                        throw new ProtocolException('Request must be JSON object', -32600);
                    }
                }

                // Check for initialize method - skip auth if this is initialize
                $isInitialize = ($data['method'] ?? '') === 'initialize';

                // THEN check authentication (skip for authless mode OR initialize)
                if (!$isInitialize && empty($this->contextData) && !$isAuthless) {
                    throw new AuthenticationException('Try putting this URL into an MCP enabled LLM, Like Claude.ai or GPT. Authentication required');
                }

                $this->sessionId = $this->negotiateSessionId($request, $data);

                if ($isInitialize) {
                    // negotiate the protocol
                    $clientProtocolVersion = $data['params']['protocolVersion'] ?? null;
                    if (!$clientProtocolVersion) {
                        throw new ProtocolException('Invalid params: protocolVersion required', -32602);
                    }

                    // This is where we negotiate the protocol
                    $protocolVersion = $this->versionNegotiator->negotiate($clientProtocolVersion);

                    // Create the combined sessionID with protocol version
                    $this->sessionId = $protocolVersion . '_' . $this->sessionId;

                    return $this->messageHandler->handleInitialize($data['params'] ?? [], $data['id'] ?? null, $this->sessionId, $protocolVersion, $response);
                }

                return $this->handleMCPRequest($request, $response, $data);
            }

            if ($request->getMethod() === 'GET') {
                // For authless mode, ensure we have minimal context
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
                'message' => $e->getMessage(),
                'session_id' => $this->sessionId
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

        // Check for existing session ID in header or route
        $existingSessionId = $this->extractSessionIdFromRequest($request);

        if ($method === 'GET') {
            // GET requires existing session ID
            if (!$existingSessionId) {
                throw new ProtocolException('Session ID required for GET requests', -32001);
            }

            // Verify session exists in storage (using full protocolVersion_sessionId)
            $sessionData = $this->storage->getSession($existingSessionId);
            if (!$sessionData) {
                throw new ProtocolException('Invalid or expired session ID', -32001);
            }

            return $existingSessionId;
        }

        if ($method === 'POST') {
            // Check if this is an initialize request first
            if (($data['method'] ?? '') === 'initialize') {
                // Generate just the numeric session ID - protocol gets added later in initialize
                $newSessionId = $this->generateSessionId();
                return $newSessionId;
            }

            // For non-initialize requests, we need a valid existing session
            if (!$existingSessionId) {
                $this->logger->warning('No session ID found in request', [
                    'method' => $data['method'] ?? 'unknown',
                    'headers' => array_keys($request->getHeaders())
                ]);
                throw new ProtocolException('Session ID required for non-initialize requests', -32001);
            }

            // Verify session exists in storage (using full protocolVersion_sessionId)
            $sessionData = $this->storage->getSession($existingSessionId);
            if (!$sessionData) {
                $this->logger->warning('Session not found in storage', [
                    'session_id' => $existingSessionId,
                    'method' => $data['method'] ?? 'unknown'
                ]);
                throw new ProtocolException('Invalid or expired session ID', -32001);
            }

            return $existingSessionId;
        }

        return null;
    }

    /**
     * Extract session ID from request headers or route parameters
     */
    /**
 * Extract session ID from request headers or route parameters
 */
    private function extractSessionIdFromRequest(Request $request): ?string
    {
        $logFile = '/var/www/devsa/logs/uncaught.log';
        $serverTag = "[SESSION-EXTRACT-DEBUG]";

        file_put_contents($logFile, "{$serverTag} === STARTING SESSION ID EXTRACTION ===\n", FILE_APPEND);
        file_put_contents($logFile, "{$serverTag} Method: {$request->getMethod()}\n", FILE_APPEND);
        file_put_contents($logFile, "{$serverTag} URI: {$request->getUri()}\n", FILE_APPEND);

        foreach ($request->getHeaders() as $name => $values) {
            file_put_contents($logFile, "{$serverTag} Header: '{$name}' = '" . implode(', ', $values) . "'\n", FILE_APPEND);

            $lowerName = strtolower($name);

            if ($lowerName === 'mcp-session-id') {
                file_put_contents($logFile, "{$serverTag} FOUND MCP-SESSION-ID HEADER!\n", FILE_APPEND);
                file_put_contents($logFile, "{$serverTag} Header value array: " . json_encode($values) . "\n", FILE_APPEND);
                file_put_contents($logFile, "{$serverTag} First value: '{$values[0]}'\n", FILE_APPEND);
                file_put_contents($logFile, "{$serverTag} Returning session ID: '{$values[0]}'\n", FILE_APPEND);
                return $values[0]; // Return the full protocolVersion_sessionId
            }
        }

        file_put_contents($logFile, "{$serverTag} No mcp-session-id header found, checking route parameters...\n", FILE_APPEND);

        // Check route parameters
        $route = $request->getAttribute('__route__');
        file_put_contents($logFile, "{$serverTag} Route object: " . ($route ? get_class($route) : 'NULL') . "\n", FILE_APPEND);

        if ($route && method_exists($route, 'getArgument')) {
            file_put_contents($logFile, "{$serverTag} Route has getArgument method, checking for sessID...\n", FILE_APPEND);
            $routeSessionId = $route->getArgument('sessID');
            file_put_contents($logFile, "{$serverTag} Route sessID argument: " . ($routeSessionId ?? 'NULL') . "\n", FILE_APPEND);

            if ($routeSessionId) {
                file_put_contents($logFile, "{$serverTag} Found session ID in route: '{$routeSessionId}'\n", FILE_APPEND);
                return $routeSessionId;
            }
        } else {
            file_put_contents($logFile, "{$serverTag} Route is null or missing getArgument method\n", FILE_APPEND);
        }

        file_put_contents($logFile, "{$serverTag} No session ID in route, checking URI path segments...\n", FILE_APPEND);

        // Generic URI path extraction - look for protocolVersion_sessionId format
        $path = $request->getUri()->getPath();
        file_put_contents($logFile, "{$serverTag} URI Path: '{$path}'\n", FILE_APPEND);

        $pathSegments = explode('/', trim($path, '/'));
        file_put_contents($logFile, "{$serverTag} Path segments: " . json_encode($pathSegments) . "\n", FILE_APPEND);

        foreach ($pathSegments as $index => $segment) {
            file_put_contents($logFile, "{$serverTag} Checking segment[{$index}]: '{$segment}'\n", FILE_APPEND);

            // Look for protocolVersion_sessionId pattern
            if (preg_match('/^[a-zA-Z0-9.-]+_[a-zA-Z0-9]+$/', $segment)) {
                file_put_contents($logFile, "{$serverTag} Segment matches protocolVersion_sessionId pattern!\n", FILE_APPEND);
                file_put_contents($logFile, "{$serverTag} Found session ID in URI path: '{$segment}'\n", FILE_APPEND);
                return $segment;
            } else {
                file_put_contents($logFile, "{$serverTag} Segment does not match pattern\n", FILE_APPEND);
            }
        }

        file_put_contents($logFile, "{$serverTag} === NO SESSION ID FOUND - RETURNING NULL ===\n", FILE_APPEND);
        return null;
    }

    /**
     * Get base URL from request with fallback
     */
    private function getBaseUrl(Request $request): string
    {
        // First try to get from config (for tests and when explicitly set)
        if (!empty($this->config['base_url'])) {
            return $this->config['base_url'];
        }

        // Extract from request URI
        $uri = $request->getUri();
        $scheme = 'https';
        $host = $uri->getHost() ?: 'localhost';
        $port = $uri->getPort();

        $baseUrl = $scheme . '://' . $host;
        if ($port && (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))) {
            $baseUrl .= ':' . $port;
        }

        return $baseUrl;
    }

    /**
     * Extract protocol version from initialize message (POST requests only)
     */
    private function extractProtocolFromInitialize(Request $request, ?array $parsedData = null): ?string
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $data = $parsedData;

        // Only read body if we don't have pre-parsed data
        if ($data === null) {
            $body = (string) $request->getBody();
            if (!empty($body)) {
                $data = json_decode($body, true);
                // Rewind the stream for future reads
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
            }
        }

        if ($data && isset($data['method']) && $data['method'] === 'initialize' && isset($data['params']['protocolVersion'])) {
            return $data['params']['protocolVersion'];
        }

        return null;
    }

    /**
     * Get protocol version for streaming connections (GET requests)
     * Extract from the stored session or parse from sessionId
     */
    private function getSessionProtocolVersion(Request $request): string
    {
        // Get the negotiated version from session
        $sessionData = $this->storage->getSession($this->sessionId);
        if (!$sessionData || !isset($sessionData['protocol_version'])) {
            // Try to extract from sessionId if it's in protocolVersion_sessionId format
            if ($this->sessionId && strpos($this->sessionId, '_') !== false) {
                $parts = explode('_', $this->sessionId, 2);
                if (count($parts) === 2) {
                    $protocolFromSessionId = $parts[0];
                    // Validate it's a known protocol version
                    if (in_array($protocolFromSessionId, $this->config['supported_versions'])) {
                        return $protocolFromSessionId;
                    }
                }
            }
            throw new ProtocolException('No protocol version found in session', -32001);
        }

        $negotiatedVersion = $sessionData['protocol_version'];

        // Only check MCP-Protocol-Version header for 2025-06-18 (spec requirement)
        if ($negotiatedVersion === '2025-06-18') {
            $isAuthless = $this->config['auth']['authless'];

            $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');

            if (!$headerVersion && !$isAuthless) {
                // Only require header for OAuth mode (security critical)
                throw new ProtocolException('MCP-Protocol-Version header required for version 2025-06-18', -32600);
            }

            if ($headerVersion && $headerVersion !== $negotiatedVersion) {
                throw new ProtocolException('MCP-Protocol-Version header must match negotiated version', -32600);
            }
        }

        return $negotiatedVersion;
    }

    /**
     * Determine which transport to use based on protocol version
     */
    private function shouldUseStreamableHTTP(string $protocolVersion): bool
    {
        return in_array($protocolVersion, ['2025-03-26', '2025-06-18']);
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
        // At this point, $data should always be parsed and valid, but let's be defensive
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

    public function addTool(string $name, callable $handler, array $schema = []): void
    {
        $this->toolRegistry->register($name, $handler, $schema);
    }

    public function addPrompt(string $name, callable $handler, array $schema = []): void
    {
        $this->promptRegistry->register($name, $handler, $schema);
    }

    public function addResource(string $uri, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->register($uri, $handler, $schema);
    }

    public function addResourceTemplate(string $uriTemplate, callable $handler, array $schema = []): void
    {
        $this->resourceRegistry->registerTemplate($uriTemplate, $handler, $schema);
    }

    public function setContext(array $contextData): void
    {
        $this->contextData = $contextData;
    }

    /**
     * Get default configuration
     *
     * This is the one location where every developer-facing configuration exists.
     * Override them, if you need to, or let this repository handle things for you.
     */
    private function getDefaultConfig(): array
    {
        return [
            // Core MCP Protocol Configuration
            'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
            'base_url' => null, // MCP base url
            'session_user_id' => null, // key name for session validation around "is the user logged in?"
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'session_lifetime' => 3600,    // seconds
            'test_mode' => false,           // set to true in tests
            'server_info' => [
                'name' => 'WaaSuP MCP SaaS Server',
                'version' => '2.0.0'
            ],
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
                'base_url' => '', // OAuth base url
                'auth_server' => [
                    'endpoints' => [
                        'authorize' => '/oauth/authorize',
                        'token' => '/oauth/token',
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
                'table_mapping' => []
            ]
        ];
    }
}
