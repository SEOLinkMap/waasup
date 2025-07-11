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

class MCPSaaSServer
{
    private StorageInterface $storage;
    private ToolRegistry $toolRegistry;
    private PromptRegistry $promptRegistry;
    private ResourceRegistry $resourceRegistry;
    private VersionNegotiator $versionNegotiator;
    private MessageHandler $messageHandler;
    private SSETransport $sseTransport;
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
                    throw new AuthenticationException('Authentication required');
                }

                $this->sessionId = $this->negotiateSessionId($request, $data);
                return $this->handleMCPRequest($request, $response, $data);
            }

            if ($request->getMethod() === 'GET') {
                if (empty($this->contextData)) {
                    throw new AuthenticationException('Authentication required');
                }
                $this->sessionId = $this->negotiateSessionId($request);
                return $this->handleSSEConnection($request, $response);
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
            return $this->createErrorResponse($response, -32000, $e->getMessage(), null, 401);
        } catch (ProtocolException $e) {
            $this->logger->error(
                'Protocol error',
                [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
                ]
            );
            return $this->createErrorResponse($response, $e->getCode(), $e->getMessage());
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
            throw new ProtocolException('Session required', -32001);
        }

        return null;
    }

    /**
     * Handle SSE connection for real-time message delivery
     */
    private function handleSSEConnection(Request $request, Response $response): Response
    {
        $this->logger->info(
            'SSE connection established',
            [
            'session_id' => $this->sessionId,
            'context' => $this->contextData
            ]
        );

        return $this->sseTransport->handleConnection(
            $request,
            $response,
            $this->sessionId,
            $this->contextData
        );
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
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'supported_versions' => ['2025-03-18', '2024-11-05'],
            'server_info' => [
                'name' => 'WaaSuP MCP SaaS Server',
                'version' => '1.0.0'
            ],
            'sse' => [
                'keepalive_interval' => 1,      // seconds
                'max_connection_time' => 1800,  // 30 minutes
                'switch_interval_after' => 60   // switch to longer intervals after 1 minute
            ]
        ];
    }
}
