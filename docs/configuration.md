# Configuration

This document covers the actual configuration options available in the MCP SaaS Server based on what the software actually implements.

## Table of Contents

- [Server Configuration](#server-configuration)
- [Database Configuration](#database-configuration)
- [Authentication Configuration](#authentication-configuration)
- [Transport Configuration](#transport-configuration)
- [Storage Configuration](#storage-configuration)
- [Framework Integration](#framework-integration)
- [Database Schema](#database-schema)

## Server Configuration

### Basic Server Setup

```php
use Seolinkmap\Waasup\MCPSaaSServer;

$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'My MCP Server',
        'version' => '1.0.0'
    ],
    'sse' => [
        'keepalive_interval' => 1,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60,
        'test_mode' => false
    ]
];

$server = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $config,
    $logger // Optional PSR-3 logger
);
```

### Protocol Version Support

```php
// Configure which MCP protocol versions to support
$config['supported_versions'] = [
    '2025-06-18',  // Latest with OAuth Resource Server + Elicitation
    '2025-03-26',  // Stable with Tool Annotations + Audio Content
    '2024-11-05'   // Base version
];
```

**Note**: The server configuration itself does not use environment variables. All configuration must be passed directly to the constructors. However, individual tools may use environment variables for external API keys and services.

## Database Configuration

### PDO Connection Setup

```php
// You must set up your own PDO connection
$pdo = new PDO('mysql:host=localhost;dbname=mcp_server', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
```

### Database Storage Configuration

```php
use Seolinkmap\Waasup\Storage\DatabaseStorage;

$storage = new DatabaseStorage($pdo, [
    'table_prefix' => 'mcp_',           // Default: 'mcp_'
    'cleanup_interval' => 3600          // Default: 3600 seconds
]);
```

### Custom Table Mapping for Existing Applications

If you have an existing application with user/agency tables you want to preserve, you can configure custom table mapping:

```php
// Basic integration - map existing user/agency tables
$storage = new DatabaseStorage($pdo, [
    'table_prefix' => 'mcp_',
    'table_mapping' => [
        'agencies' => 'companies',      // Your existing companies table
        'users' => 'app_users'          // Your existing users table
        // Other tables use mcp_ prefix defaults
    ]
]);
```

**📖 For complete custom table configuration:** See the [Custom Table Configuration Guide](../examples/database/custom-table-configurations.md) for detailed examples, required field mappings, database preparation steps, and framework-specific integration patterns.


### Required Database Tables

The software expects these tables to exist (you must create them manually):

**Core Tables:**
- `mcp_messages` - Stores queued messages for SSE delivery
- `mcp_sessions` - Stores session data with protocol versions
- `mcp_agencies` - Stores agency/context data
- `mcp_users` - Stores user data (optional for social auth)

**OAuth Tables:**
- `mcp_oauth_tokens` - Stores access tokens for validation
- `mcp_oauth_clients` - Stores OAuth client registrations

**Response Storage Tables (for async operations):**
- `mcp_sampling_responses` - Stores LLM sampling responses
- `mcp_roots_responses` - Stores client filesystem responses
- `mcp_elicitation_responses` - Stores user input responses (2025-06-18)

📖 **See the [Database Schema Documentation](../examples/database/database-schema.sql) for complete CREATE TABLE statements...

## Authentication Configuration

### Basic Auth Configuration

```php
$config['auth'] = [
    'context_types' => ['agency'],      // Default: ['agency', 'user']
    'validate_scope' => false,          // Default: false
    'required_scopes' => [],            // Default: []
    'base_url' => 'https://your-domain.com',  // Default: 'https://localhost'
    'resource_server_metadata' => true,       // OAuth Resource Server (2025-06-18)
    'require_resource_binding' => true        // RFC 8707 Resource Indicators
];
```

### Context Validation

The software validates contexts by looking up:
- Agency UUID in `mcp_agencies` table where `active = 1`
- User ID in `mcp_users` table (if using user context)

### Token Validation

Tokens are validated against the `mcp_oauth_tokens` table:

```php
// This happens automatically in AuthMiddleware
$tokenData = $storage->validateToken($accessToken, $context);
```

### OAuth Endpoint Configuration

#### Overview

The MCP SaaS Server allows you to configure custom OAuth endpoint URLs to avoid conflicts with existing OAuth systems on your server. This is essential when you already have an OAuth implementation using the default `/oauth/*` paths.

#### Complete Configuration Example with Custom OAuth URLs

```php
<?php
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Auth\OAuthServer;

// CUSTOM OAuth configuration to avoid conflicts with existing OAuth systems
$config = [
    'server_info' => [
        'name' => 'My MCP Server',
        'version' => '1.0.0'
    ],
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-api/v1/authorize',    // NOT /oauth/authorize
            'token' => '/mcp-api/v1/token',            // NOT /oauth/token
            'register' => '/mcp-api/v1/register',      // NOT /oauth/register
            'revoke' => '/mcp-api/v1/revoke',          // NOT /oauth/revoke
            'resource' => '/mcp-api/v1/resource'       // NOT /oauth/resource
        ],
        'scopes_supported' => ['mcp:read', 'mcp:write'],
        'base_url' => 'https://your-domain.com'
    ],
    'auth' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-api/v1/authorize',    // Must match discovery
            'token' => '/mcp-api/v1/token',            // Must match discovery
            'register' => '/mcp-api/v1/register',      // Must match discovery
            'revoke' => '/mcp-api/v1/revoke',          // Must match discovery
            'resource' => '/mcp-api/v1/resource'       // Must match discovery
        ],
        'context_types' => ['agency'],
        'base_url' => 'https://your-domain.com'
    ]
];

// Create providers with CUSTOM OAuth endpoint configuration
$mcpProvider = new SlimMCPProvider(
    $storage, $toolRegistry, $promptRegistry, $resourceRegistry,
    $responseFactory, $streamFactory, $config
);

$oauthServer = new OAuthServer($storage, $responseFactory, $streamFactory, $oauthConfig);
```

#### Slim Framework Integration with Custom OAuth Paths

```php
use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Register CUSTOM OAuth routes (matching your configuration above)
$app->get('/mcp-api/v1/authorize', [$oauthServer, 'authorize']);
$app->post('/mcp-api/v1/verify', [$oauthServer, 'verify']);
$app->post('/mcp-api/v1/consent', [$oauthServer, 'consent']);
$app->post('/mcp-api/v1/token', [$oauthServer, 'token']);
$app->post('/mcp-api/v1/revoke', [$oauthServer, 'revoke']);
$app->post('/mcp-api/v1/register', [$oauthServer, 'register']);

// Social provider callbacks with custom paths
$app->get('/mcp-api/v1/google/callback', [$oauthServer, 'handleGoogleCallback']);
$app->get('/mcp-api/v1/linkedin/callback', [$oauthServer, 'handleLinkedinCallback']);
$app->get('/mcp-api/v1/github/callback', [$oauthServer, 'handleGithubCallback']);

// Discovery endpoints (always use standard .well-known paths)
$app->get('/.well-known/oauth-authorization-server', [$mcpProvider, 'handleAuthDiscovery']);

// MCP endpoints
$app->map(['GET', 'POST'], '/mcp/{agencyUuid}[/{sessID}]', [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());
```

#### Real-World Custom OAuth URL Examples

**Example 1: Namespace Separation**
```php
// Your existing OAuth uses /oauth/*, MCP uses /mcp-oauth/*
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-oauth/authorize',
            'token' => '/mcp-oauth/token',
            'register' => '/mcp-oauth/register'
        ]
    ]
];
```

**Example 2: API Versioning**
```php
// Use versioned paths for future compatibility
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/api/v1/oauth/authorize',
            'token' => '/api/v1/oauth/token',
            'register' => '/api/v1/oauth/register'
        ]
    ]
];
```

**Example 3: Multi-Tenant/Brand Support**
```php
// Different OAuth paths per brand or tenant
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/acme-corp/oauth/authorize',
            'token' => '/acme-corp/oauth/token',
            'register' => '/acme-corp/oauth/register'
        ]
    ]
];
```

#### Testing Your Custom OAuth URLs

```bash
# Test discovery shows YOUR custom URLs
curl https://your-server.com/.well-known/oauth-authorization-server

# Should return YOUR custom endpoints:
# {
#   "authorization_endpoint": "https://your-server.com/mcp-api/v1/authorize",
#   "token_endpoint": "https://your-server.com/mcp-api/v1/token",
#   ...
# }

# Test that authentication errors also show YOUR custom URLs
curl -X POST https://your-server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"ping","id":1}'

# 401 response should include YOUR custom OAuth URLs in error.data.oauth
```

#### Default Configuration (if you don't set custom URLs)

```php
// If you don't configure oauth_endpoints, these defaults are used:
$defaultOAuthEndpoints = [
    'authorize' => '/oauth/authorize',      // Standard OAuth path
    'token' => '/oauth/token',              // Standard OAuth path
    'register' => '/oauth/register',        // Standard OAuth path
    'revoke' => '/oauth/revoke',            // Standard OAuth path
    'resource' => '/oauth/resource'         // Standard OAuth path
];
```


### Auth Middleware Configuration

```php
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;

$authMiddleware = new AuthMiddleware(
    $storage,
    $responseFactory,
    $streamFactory,
    [
        'context_types' => ['agency'],           // Which context types to accept
        'validate_scope' => false,               // Whether to check token scopes
        'required_scopes' => ['mcp:read'],      // Required scopes if validation enabled
        'base_url' => 'https://your-domain.com' // Base URL for OAuth discovery
    ]
);
```

### OAuth 2.1 Configuration (2025-06-18)

For full OAuth 2.1 with RFC 8707 Resource Indicators:

```php
$config['auth'] = [
    'context_types' => ['agency'],
    'validate_scope' => true,
    'required_scopes' => ['mcp:read'],
    'base_url' => 'https://your-domain.com',
    'resource_server_metadata' => true,
    'require_resource_binding' => true,  // RFC 8707 compliance
    'token_endpoint_auth_methods_supported' => ['client_secret_post', 'private_key_jwt', 'none'],
    'audience_validation_required' => true
];
```

## Transport Configuration

### Server-Sent Events (SSE)

```php
$config['sse'] = [
    'keepalive_interval' => 1,      // Seconds between keepalive messages
    'max_connection_time' => 1800,  // 30 minutes max connection time
    'switch_interval_after' => 60,  // Switch to longer intervals after 1 minute
    'test_mode' => false            // Set true for testing (disables polling)
];
```

### Streamable HTTP (2025-03-26+)

```php
$config['streamable_http'] = [
    'keepalive_interval' => 2,      // Seconds between keepalive (longer than SSE)
    'max_connection_time' => 1800,  // 30 minutes max connection time
    'switch_interval_after' => 60,  // Switch to longer intervals after 1 minute
    'test_mode' => false            // Set true for testing
];
```

**Important**: In production, set `test_mode => false`. In testing, set `test_mode => true` to avoid long-running connections.

## Storage Configuration

### Memory Storage (Testing/Development)

```php
use Seolinkmap\Waasup\Storage\MemoryStorage;

// WARNING: MemoryStorage blocks production use
$storage = new MemoryStorage();

// Add test data manually
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1,
    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'name' => 'Test Agency',
    'active' => true
]);

$storage->addToken('test-token', [
    'access_token' => 'test-token',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 3600,
    'revoked' => false
]);
```

### Database Storage (Production)

```php
use Seolinkmap\Waasup\Storage\DatabaseStorage;

$storage = new DatabaseStorage($pdo, [
    'table_prefix' => 'mcp_',
    'cleanup_interval' => 3600
]);
```

## Framework Integration

### Slim Framework Integration

```php
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config,
    $logger  // Optional
);

// Setup discovery route
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

// Setup MCP routes (flexible parameter names)
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());
```

### Flexible Route Parameter Names

The `AuthMiddleware` accepts any of these parameter names:
- `{agencyUuid}` - For agency contexts
- `{userId}` - For user contexts
- `{contextId}` - Generic context identifier

Example routes:
```php
// All of these work:
'/mcp/{agencyUuid}'
'/mcp/{userId}'
'/mcp/{contextId}'
'/mcp/{agencyUuid}/{sessionId}'
```

### Laravel Integration

```php
use Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider;

// Add to config/app.php
'providers' => [
    // ...
    \Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
];

// Routes are auto-registered by the service provider
```

## Tool Registry Configuration

```php
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

$toolRegistry = new ToolRegistry();

// Register a simple tool
$toolRegistry->register('echo', function($params, $context) {
    return [
        'message' => $params['message'] ?? 'Hello!',
        'received_params' => $params,
        'context_available' => !empty($context)
    ];
}, [
    'description' => 'Echo a message back',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'message' => [
                'type' => 'string',
                'description' => 'Message to echo'
            ]
        ],
        'required' => ['message']
    ],
    'annotations' => [  // Tool annotations (2025-03-26+)
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false
    ]
]);
```

### Tool Environment Variable Usage

Tools can use environment variables for external services:

```php
$toolRegistry->register('get_weather', function($params, $context) {
    // Tools CAN use environment variables for API keys
    $apiKey = $_ENV['WEATHER_API_KEY'] ?? null;

    if (!$apiKey) {
        return ['error' => 'Weather API key not configured'];
    }

    // Use $apiKey to call external weather API
    return ['weather' => 'sunny', 'temp' => '22°C'];
}, [
    'description' => 'Get weather data from external API',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'Location name']
        ],
        'required' => ['location']
    ]
]);
```

## Prompt Registry Configuration

```php
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;

$promptRegistry = new PromptRegistry();

$promptRegistry->register('greeting', function($arguments, $context) {
    $name = $arguments['name'] ?? 'there';
    return [
        'description' => 'A friendly greeting prompt',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Please greet {$name} in a friendly way."
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate a friendly greeting prompt',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Name of the person to greet'
            ]
        ]
    ]
]);
```

## Resource Registry Configuration

```php
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;

$resourceRegistry = new ResourceRegistry();

// Static resource
$resourceRegistry->register('server://status', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode([
                    'status' => 'healthy',
                    'timestamp' => date('c'),
                    'uptime' => time() - $_SERVER['REQUEST_TIME']
                ])
            ]
        ]
    ];
}, [
    'name' => 'Server Status',
    'description' => 'Current server status and health information',
    'mimeType' => 'application/json'
]);

// Resource template
$resourceRegistry->registerTemplate('file://{path}', function($uri, $context) {
    $path = str_replace('file://', '', $uri);
    $safePath = basename($path); // Simple security measure

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "Content for file: {$safePath}\n(Implement actual file reading)"
            ]
        ]
    ];
}, [
    'name' => 'File Resource',
    'description' => 'Read file contents from the server',
    'mimeType' => 'text/plain'
]);
```

## Database Schema

### Required Tables Overview

The `DatabaseStorage` implementation requires these tables to exist:

**Core Tables:**
- `mcp_messages` - Queued messages for async delivery
- `mcp_sessions` - Session data with protocol versions
- `mcp_agencies` - Agency/tenant context data
- `mcp_users` - User data (optional, for social auth)

**OAuth Tables:**
- `mcp_oauth_tokens` - Access tokens with RFC 8707 support
- `mcp_oauth_clients` - OAuth client registrations

**Response Storage Tables:**
- `mcp_sampling_responses` - LLM sampling responses
- `mcp_roots_responses` - Client filesystem responses
- `mcp_elicitation_responses` - User input responses (2025-06-18)

**⚠️ Important:** You must create these tables manually before using `DatabaseStorage`.

📖 **See the [Database Schema Documentation](../examples/database/database-schema.sql) for complete CREATE TABLE statements, field descriptions, and database-specific variations.**

## Complete Working Example

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory};
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

// For testing, use MemoryStorage with sample data
$storage = new MemoryStorage();

// Add test agency
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1,
    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'name' => 'Test Agency',
    'active' => true
]);

// Add test token
$storage->addToken('test-token', [
    'access_token' => 'test-token',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 3600,
    'revoked' => false
]);

// Create registries
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Register sample tools/prompts/resources
$toolRegistry->register('echo', function($params, $context) {
    return ['message' => $params['message'] ?? 'Hello!'];
}, ['description' => 'Echo a message']);

$resourceRegistry->register('test://status', function($uri, $context) {
    return [
        'contents' => [
            ['uri' => $uri, 'mimeType' => 'application/json', 'text' => '{"status":"ok"}']
        ]
    ];
});

// Configuration
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Test MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'http://localhost:8080'
    ],
    'sse' => [
        'test_mode' => true  // Important for testing
    ]
];

// PSR-17 factories
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();

// MCP Provider
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config
);

// Slim app
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// Routes
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

$app->run();
```

### Testing the Configuration

```bash
# Test authentication discovery
curl http://localhost:8080/.well-known/oauth-authorization-server

# Test MCP initialization
curl -X POST http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer test-token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"Test"}},"id":1}'
```
