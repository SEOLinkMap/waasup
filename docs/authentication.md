# Authentication & OAuth

The MCP SaaS Server implements OAuth 2.1 with multi-tenant context-based authentication. This document covers the complete authentication flow, token management, and security implementation.

## Overview

The authentication system provides:

- **OAuth 2.1 compliance** with authorization code flow
- **Multi-tenant architecture** with agency-based contexts
- **Social provider integration** (Google, LinkedIn, GitHub)
- **Discovery endpoints** for OAuth configuration
- **PSR-15 middleware** for request authentication
- **Flexible storage** supporting database and memory backends

### Authentication Flow

1. Client discovers OAuth endpoints via `.well-known/oauth-authorization-server`
2. Client redirects user to authorization endpoint
3. User authenticates via email/password or social providers
4. User grants consent for specific agency context
5. Server returns authorization code
6. Client exchanges code for access token
7. Client uses token for MCP API requests with context

## OAuth Server Implementation

The `OAuthServer` class handles the complete OAuth 2.1 flow with built-in social provider support.

### Basic Setup

```php
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);

$oauthConfig = [
    'base_url' => 'https://your-server.com',
    'google' => [
        'client_id' => 'your-google-client-id',
        'client_secret' => 'your-google-client-secret',
        'redirect_uri' => 'https://your-server.com/oauth/google/callback'
    ],
    'linkedin' => [
        'client_id' => 'your-linkedin-client-id',
        'client_secret' => 'your-linkedin-client-secret',
        'redirect_uri' => 'https://your-server.com/oauth/linkedin/callback'
    ],
    'github' => [
        'client_id' => 'your-github-client-id',
        'client_secret' => 'your-github-client-secret',
        'redirect_uri' => 'https://your-server.com/oauth/github/callback'
    ]
];

$oauthServer = new OAuthServer(
    $storage,
    $responseFactory,
    $streamFactory,
    $oauthConfig
);
```

### Authorization Endpoint

The authorization endpoint handles user authentication and consent:

```php
// GET /oauth/authorize?response_type=code&client_id=...&redirect_uri=...&scope=...&state=...
public function handleAuthorize(Request $request, Response $response): Response
{
    return $this->oauthServer->authorize($request, $response);
}
```

This will:
1. Validate the OAuth parameters
2. Check if user is already authenticated
3. Render authentication form with social provider options
4. Handle consent after authentication

### Authentication Verification

Users can authenticate via email/password or social providers:

```php
// POST /oauth/verify
public function handleVerify(Request $request, Response $response): Response
{
    return $this->oauthServer->verify($request, $response);
}
```

### Consent Handling

After authentication, users must consent to app access:

```php
// POST /oauth/consent
public function handleConsent(Request $request, Response $response): Response
{
    return $this->oauthServer->consent($request, $response);
}
```

### Token Exchange

Exchange authorization code for access token:

```php
// POST /oauth/token
public function handleToken(Request $request, Response $response): Response
{
    return $this->oauthServer->token($request, $response);
}
```

Supports both authorization code grant and refresh token grant.

### Social Provider Callbacks

Handle OAuth callbacks from social providers:

```php
// GET /oauth/google/callback
public function handleGoogleCallback(Request $request, Response $response): Response
{
    return $this->oauthServer->handleGoogleCallback($request, $response);
}

// GET /oauth/linkedin/callback
public function handleLinkedinCallback(Request $request, Response $response): Response
{
    return $this->oauthServer->handleLinkedinCallback($request, $response);
}

// GET /oauth/github/callback
public function handleGithubCallback(Request $request, Response $response): Response
{
    return $this->oauthServer->handleGithubCallback($request, $response);
}
```

## Authentication Middleware

The PSR-15 middleware handles request authentication automatically.

### Middleware Setup

```php
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;

$authMiddleware = new AuthMiddleware(
    $storage,
    $responseFactory,
    $streamFactory,
    [
        'context_types' => ['agency'],
        'base_url' => 'https://your-server.com'
    ]
);

// Add to Slim app
$app->add($authMiddleware);
```

### How It Works

The middleware:
1. Extracts context identifier from route (agency UUID)
2. Validates the context exists and is active
3. Extracts Bearer token from Authorization header
4. Validates token against storage
5. Adds context data to request attributes
6. Returns OAuth discovery response if authentication fails

### Token Validation

Token validation is handled directly through the storage interface:

```php
// In your storage implementation
public function validateToken(string $accessToken, array $context = []): ?array
{
    $sql = "SELECT * FROM `{$this->tablePrefix}oauth_tokens`
            WHERE `access_token` = :token
            AND `expires_at` > :current_time
            AND `revoked` = 0
            LIMIT 1";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':token' => $accessToken,
        ':current_time' => $this->getCurrentTimestamp()
    ]);

    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}
```

## Discovery Endpoints

The `WellKnownProvider` implements OAuth discovery as per RFC 8414.

### OAuth Authorization Server Metadata

```php
use Seolinkmap\Waasup\Discovery\WellKnownProvider;

$wellKnownProvider = new WellKnownProvider($config);

// GET /.well-known/oauth-authorization-server
public function handleAuthDiscovery(Request $request, Response $response): Response
{
    return $wellKnownProvider->authorizationServer($request, $response);
}
```

Returns metadata including:
- Authorization and token endpoints
- Supported grant types and scopes
- Authentication methods
- PKCE support

## Multi-Tenant Context

The system supports agency-based isolation through context validation.

### Context Management

```php
// Agency context is validated through storage
public function getContextData(string $identifier, string $type = 'agency'): ?array
{
    $sql = "SELECT * FROM `{$this->tablePrefix}agencies`
            WHERE `uuid` = :identifier AND `active` = 1";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([':identifier' => $identifier]);

    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}
```

### Context-Aware Operations

```php
// Access context in your MCP handlers
public function handleToolCall(Request $request): Response
{
    $context = $request->getAttribute('mcp_context');
    $agencyData = $context['context_data'];

    // Use agency data for business logic
    $agencyId = $agencyData['id'];
    $agencySettings = json_decode($agencyData['settings'], true);

    // Implement agency-specific rate limiting, feature access, etc.
}
```

## Storage Interface

The authentication system uses the following storage methods:

```php
interface StorageInterface
{
    // Token management
    public function validateToken(string $accessToken, array $context = []): ?array;
    public function storeAccessToken(array $tokenData): bool;
    public function getTokenByRefreshToken(string $refreshToken, string $clientId): ?array;
    public function revokeToken(string $token): bool;

    // OAuth flow
    public function getOAuthClient(string $clientId): ?array;
    public function storeOAuthClient(array $clientData): bool;
    public function storeAuthorizationCode(string $code, array $codeData): bool;
    public function getAuthorizationCode(string $code, string $clientId): ?array;
    public function revokeAuthorizationCode(string $code): bool;

    // Context management
    public function getContextData(string $identifier, string $type = 'agency'): ?array;

    // User management
    public function verifyUserCredentials(string $email, string $password): ?array;
    public function findUserByEmail(string $email): ?array;
    public function findUserByGoogleId(string $googleId): ?array;
    public function findUserByLinkedinId(string $linkedinId): ?array;
    public function findUserByGithubId(string $githubId): ?array;
}
```

## Integration Examples

### Slim Framework Integration

```php
use Slim\Factory\AppFactory;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

$app = AppFactory::create();

// Create MCP provider with auth config
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config
);

// OAuth discovery endpoints
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

// OAuth endpoints
$app->get('/oauth/authorize', [$oauthServer, 'authorize']);
$app->post('/oauth/verify', [$oauthServer, 'verify']);
$app->post('/oauth/consent', [$oauthServer, 'consent']);
$app->post('/oauth/token', [$oauthServer, 'token']);

// Social provider callbacks
$app->get('/oauth/google/callback', [$oauthServer, 'handleGoogleCallback']);
$app->get('/oauth/linkedin/callback', [$oauthServer, 'handleLinkedinCallback']);
$app->get('/oauth/github/callback', [$oauthServer, 'handleGithubCallback']);

// Protected MCP endpoints
$app->map(['GET', 'POST'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());
```

### Client-Side Integration

```javascript
// OAuth flow in client application
class MCPClient {
    constructor(config) {
        this.config = config;
        this.accessToken = null;
        this.refreshToken = null;
    }

    async authenticate(agencyId) {
        // Redirect to authorization endpoint
        const authUrl = new URL(this.config.authorizationEndpoint);
        authUrl.searchParams.set('response_type', 'code');
        authUrl.searchParams.set('client_id', this.config.clientId);
        authUrl.searchParams.set('redirect_uri', this.config.redirectUri);
        authUrl.searchParams.set('scope', 'mcp:read mcp:write');
        authUrl.searchParams.set('state', this.generateState());

        window.location.href = authUrl.toString();
    }

    async exchangeCodeForToken(code) {
        const response = await fetch(this.config.tokenEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                grant_type: 'authorization_code',
                code: code,
                client_id: this.config.clientId,
                client_secret: this.config.clientSecret,
                redirect_uri: this.config.redirectUri
            })
        });

        const tokens = await response.json();
        this.accessToken = tokens.access_token;
        this.refreshToken = tokens.refresh_token;
        return tokens;
    }

    async callMCP(agencyId, method, params = {}) {
        const response = await fetch(`${this.config.mcpEndpoint}/${agencyId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                method: method,
                params: params,
                id: Math.random()
            })
        });

        return response.json();
    }
}
```

## Public/Authless MCP Servers

For public MCP servers serving publicly available data, you can bypass authentication entirely. This is ideal for:

- **Repository exploration servers** (browsing GitHub repos, documentation)
- **Public API wrappers** (weather data, news feeds, public databases)
- **Educational demos** (showcasing MCP capabilities)
- **Website-as-a-MCP** (making websites MCP-accessible)

### Authless Server Setup

#### Option 1: Skip Authentication Middleware

```php
use Slim\Factory\AppFactory;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Storage\MemoryStorage;

$app = AppFactory::create();

// Use memory storage with pre-configured public context
$storage = new MemoryStorage();
$storage->addContext('public', 'agency', [
    'id' => 1,
    'name' => 'Public MCP Server',
    'active' => true
]);

// Create MCP provider without auth requirements
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    [
        'server_info' => [
            'name' => 'Public Repository Explorer',
            'version' => '1.0.0'
        ]
    ]
);

// Public MCP endpoint - NO auth middleware
$app->map(['GET', 'POST'], '/mcp/public[/{sessID}]',
    [$mcpProvider, 'handleMCP']);
```

#### Option 2: Permissive Storage Implementation

```php
class PublicStorage implements StorageInterface
{
    // Always allow access - no real authentication
    public function validateToken(string $accessToken, array $context = []): ?array
    {
        return [
            'user_id' => 1,
            'agency_id' => 1,
            'scope' => 'mcp:read',
            'expires_at' => time() + 3600,
            'revoked' => false
        ];
    }

    public function getContextData(string $identifier, string $type = 'agency'): ?array
    {
        // Accept any identifier as valid public context
        return [
            'id' => 1,
            'name' => 'Public Server',
            'active' => true,
            'context_type' => $type
        ];
    }

    // Implement other required methods as no-ops or defaults
    public function storeMessage(string $sessionId, array $messageData, array $context = []): bool
    {
        // Store in memory or database as needed
        return true;
    }

    // ... other methods return appropriate defaults
}
```

#### Option 3: Public Route with Custom Context

```php
// Mix authenticated and public routes
$app = AppFactory::create();

// Protected routes with full auth
$app->group('/mcp/{agencyUuid}', function ($group) {
    $group->map(['GET', 'POST'], '[/{sessID}]', [$mcpProvider, 'handleMCP']);
})->add($mcpProvider->getAuthMiddleware());

// Public routes without auth
$app->map(['GET', 'POST'], '/public/mcp[/{sessID}]', function (Request $request, Response $response) use ($mcpProvider) {
    // Add public context to request
    $request = $request->withAttribute('mcp_context', [
        'context_data' => ['id' => 1, 'name' => 'Public', 'active' => true],
        'token_data' => ['user_id' => 1, 'scope' => 'mcp:read'],
        'context_id' => 'public',
        'base_url' => 'https://your-server.com'
    ]);

    return $mcpProvider->getServer()->handle($request, $response);
});
```

### Public Server Use Cases

#### Repository Explorer Server

```php
// Add tools for exploring your repository
$toolRegistry->register('browse_repository', function($params, $context) {
    $path = $params['path'] ?? '';
    $basePath = __DIR__ . '/';

    // Security: ensure within repo bounds
    $fullPath = realpath($basePath . $path);
    if (!str_starts_with($fullPath, realpath($basePath))) {
        return ['error' => 'Path outside repository'];
    }

    if (is_dir($fullPath)) {
        $items = [];
        foreach (scandir($fullPath) as $item) {
            if ($item[0] === '.') continue;
            $itemPath = $fullPath . '/' . $item;
            $items[] = [
                'name' => $item,
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'path' => ltrim($path . '/' . $item, '/'),
                'size' => is_file($itemPath) ? filesize($itemPath) : null
            ];
        }
        return ['path' => $path ?: 'root', 'items' => $items];
    }

    return ['error' => 'Not a directory'];
}, [
    'description' => 'Browse repository directory structure',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Directory path to browse']
        ]
    ]
]);

$toolRegistry->register('read_file', function($params, $context) {
    $path = $params['path'];
    $basePath = __DIR__ . '/';
    $fullPath = realpath($basePath . $path);

    // Security validation
    if (!str_starts_with($fullPath, realpath($basePath)) ||
        !file_exists($fullPath) ||
        !is_file($fullPath)) {
        return ['error' => 'File not found or access denied'];
    }

    // Size limit for browser display
    if (filesize($fullPath) > 500000) {
        return ['error' => 'File too large to display'];
    }

    return [
        'path' => $path,
        'content' => file_get_contents($fullPath),
        'size' => filesize($fullPath),
        'modified' => date('Y-m-d H:i:s', filemtime($fullPath))
    ];
}, [
    'description' => 'Read contents of a repository file',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'File path to read']
        ],
        'required' => ['path']
    ]
]);
```

#### Public API Wrapper

```php
// Weather data MCP server
$toolRegistry->register('get_weather', function($params, $context) {
    $city = $params['city'];
    $apiKey = $_ENV['WEATHER_API_KEY']; // Your API key, not user's

    $url = "https://api.openweathermap.org/data/2.5/weather?q={$city}&appid={$apiKey}&units=metric";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    return [
        'city' => $data['name'],
        'temperature' => $data['main']['temp'],
        'description' => $data['weather'][0]['description'],
        'humidity' => $data['main']['humidity'],
        'wind_speed' => $data['wind']['speed']
    ];
}, [
    'description' => 'Get current weather for any city',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name']
        ],
        'required' => ['city']
    ]
]);
```

### Security Considerations for Public Servers

When running authless MCP servers:

1. **Rate Limiting**: Implement rate limiting to prevent abuse
```php
// Simple in-memory rate limiting
class RateLimiter {
    private static $requests = [];

    public static function checkLimit($ip, $limit = 100, $window = 3600): bool {
        $now = time();
        $key = $ip . ':' . floor($now / $window);

        if (!isset(self::$requests[$key])) {
            self::$requests[$key] = 0;
        }

        self::$requests[$key]++;
        return self::$requests[$key] <= $limit;
    }
}

// Use in middleware
$app->add(function (Request $request, RequestHandler $handler) {
    $ip = $request->getServerParams()['REMOTE_ADDR'];
    if (!RateLimiter::checkLimit($ip)) {
        return new Response(429); // Too Many Requests
    }
    return $handler->handle($request);
});
```

2. **Input Validation**: Strictly validate all inputs
```php
$toolRegistry->register('search_files', function($params, $context) {
    $query = $params['query'] ?? '';

    // Validate input
    if (empty($query) || strlen($query) < 2) {
        return ['error' => 'Query must be at least 2 characters'];
    }

    if (preg_match('/[^\w\s\-\.]/', $query)) {
        return ['error' => 'Invalid characters in query'];
    }

    // ... safe search implementation
});
```

3. **Resource Limits**: Prevent resource exhaustion
```php
// Limit file sizes, result counts, processing time
set_time_limit(30); // 30 second max execution
ini_set('memory_limit', '128M'); // Reasonable memory limit
```

4. **CORS Configuration**: Enable appropriate CORS for web clients
```php
$app->add(function (Request $request, RequestHandler $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
});
```

### Complete Public Server Example

```php
<?php
// examples/public-server/server.php

require_once __DIR__ . '/../../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

// Create public storage
$storage = new MemoryStorage();
$storage->addContext('public', 'agency', ['id' => 1, 'name' => 'Public Server', 'active' => true]);

// Register public tools
$toolRegistry = new ToolRegistry();
$toolRegistry->register('get_server_info', function($params, $context) {
    return [
        'server' => 'Public MCP Repository Explorer',
        'version' => '1.0.0',
        'description' => 'Explore this repository through MCP',
        'endpoints' => [
            'browse_repository' => 'Browse directory structure',
            'read_file' => 'Read file contents',
            'search_files' => 'Search for files'
        ]
    ];
}, ['description' => 'Get information about this public MCP server']);

// Add repository exploration tools here...

$config = [
    'server_info' => [
        'name' => 'Public Repository Explorer',
        'version' => '1.0.0'
    ]
];

$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    new PromptRegistry(),
    new ResourceRegistry(),
    $responseFactory,
    $streamFactory,
    $config
);

$app = AppFactory::create();

// Rate limiting middleware
$app->add(function (Request $request, RequestHandler $handler) {
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    // Implement rate limiting logic
    return $handler->handle($request);
});

// CORS middleware
$app->add(function (Request $request, RequestHandler $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
});

// Public MCP endpoint - no authentication required
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/public[/{sessID}]', function (Request $request, Response $response) use ($mcpProvider) {
    // Add public context
    $request = $request->withAttribute('mcp_context', [
        'context_data' => ['id' => 1, 'name' => 'Public', 'active' => true],
        'token_data' => ['user_id' => 1, 'scope' => 'mcp:read'],
        'context_id' => 'public',
        'base_url' => $request->getUri()->getScheme() . '://' . $request->getUri()->getHost()
    ]);

    return $mcpProvider->getServer()->handle($request, $response);
});

$app->run();
```

Public MCP servers enable powerful use cases like interactive documentation, public data exploration, and educational demonstrations while maintaining the same MCP protocol compatibility as authenticated servers.

This authentication system provides enterprise-grade security with OAuth 2.1 compliance, multi-tenant isolation, social provider support, and flexible integration options, while also supporting public/authless configurations for open data and demonstration use cases.
