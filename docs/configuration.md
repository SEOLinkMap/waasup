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
    'supported_versions' => ['2025-03-18', '2024-11-05'],
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
    '2025-03-18',  // Latest
    '2024-11-05'   // Stable
];
```

**Note**: The server does NOT use environment variables. All configuration must be passed directly to the constructors.

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

### Required Database Tables

The software expects these tables to exist (you must create them manually):

- `mcp_messages` - Stores queued messages for SSE delivery
- `mcp_sessions` - Stores session data
- `mcp_oauth_tokens` - Stores access tokens for validation
- `mcp_oauth_clients` - Stores OAuth client registrations
- `mcp_agencies` - Stores agency/context data
- `mcp_users` - Stores user data (optional)

See [Database Schema](#database-schema) section for table structures.

## Authentication Configuration

### Basic Auth Configuration

```php
$config['auth'] = [
    'context_types' => ['agency'],      // Default: ['agency', 'user']
    'validate_scope' => false,          // Default: false
    'required_scopes' => [],            // Default: []
    'base_url' => 'https://your-domain.com'  // Default: 'https://localhost'
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

**Important**: In production, set `test_mode => false`. In testing, set `test_mode => true` to avoid long-running SSE connections.

## Storage Configuration

### Memory Storage (Testing/Development)

```php
use Seolinkmap\Waasup\Storage\MemoryStorage;

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

// IMPORTANT: Also need SSE route for response delivery
$app->get('/mcp/{agencyUuid}/sse', function($request, $response, $args) use ($mcpProvider) {
    // SSE handling is built into the main handler when method=GET
    return $mcpProvider->handleMCP($request, $response);
});
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
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false
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

### Required Tables

You must create these tables manually. Here are example schemas:

```sql
-- Messages for SSE delivery
CREATE TABLE mcp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    message_data TEXT NOT NULL,
    context_data TEXT,
    created_at DATETIME NOT NULL,
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at)
);

-- Session storage
CREATE TABLE mcp_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    session_data TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_expires_at (expires_at)
);

-- OAuth tokens
CREATE TABLE mcp_oauth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(255),
    access_token VARCHAR(255) UNIQUE,
    refresh_token VARCHAR(255),
    token_type VARCHAR(50) DEFAULT 'Bearer',
    scope TEXT,
    expires_at DATETIME,
    revoked BOOLEAN DEFAULT FALSE,
    code_challenge VARCHAR(255),
    code_challenge_method VARCHAR(10),
    agency_id INT,
    user_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_access_token (access_token),
    INDEX idx_refresh_token (refresh_token),
    INDEX idx_expires_at (expires_at)
);

-- OAuth clients
CREATE TABLE mcp_oauth_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(255) UNIQUE NOT NULL,
    client_secret VARCHAR(255),
    client_name VARCHAR(255) NOT NULL,
    redirect_uris TEXT,
    grant_types TEXT,
    response_types TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Agency contexts
CREATE TABLE mcp_agencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    settings TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uuid (uuid),
    INDEX idx_active (active)
);

-- User contexts (optional)
CREATE TABLE mcp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_id INT NOT NULL,
    uuid VARCHAR(36) UNIQUE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255),
    google_id VARCHAR(255),
    linkedin_id VARCHAR(255),
    github_id VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agency_id) REFERENCES mcp_agencies(id),
    INDEX idx_email (email),
    INDEX idx_uuid (uuid)
);
```

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
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"Test"}},"id":1}'
```
