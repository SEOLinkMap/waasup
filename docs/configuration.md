# Configuration

[Installation](getting-started.md) | **Configuration** | [Authentication](authentication.md) | [Building Tools](tools.md) | [API Reference](api-reference.md)

---

## Get Help Building with WaaSuP

**Live AI-Powered Support**: Connect to `https://seolinkmap.com/mcp-repo` with your AI assistant to get instant help building with WaaSuP. This public MCP server has access to the entire WaaSuP codebase and can help you with:
- Tool development patterns and best practices
- Prompt and resource implementation
- Context handling and authentication integration
- Protocol version compatibility
- Debugging and troubleshooting
- Real code examples from the library

The WaaSuP library literally uses itself to provide support - it's the ultimate demonstration of what you can build.

---

## Overview

WaaSuP's configuration system is designed for flexibility - you only need to configure what differs from the sensible defaults. All configuration is passed through `$config` arrays that override the library's internal defaults.

## Basic Configuration Structure

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp',           // Required: Your MCP endpoint URL
    'server_info' => [
        'name' => 'Your MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'authless' => false,                             // false = OAuth required, true = public access
        'context_types' => ['agency', 'user']
    ],
    'oauth' => [
        'base_url' => 'https://yoursite.com/oauth'      // OAuth endpoints base URL
    ],
    'database' => [
        'table_prefix' => 'mcp_',                       // Simple prefixing (recommended)
        'table_mapping' => [],                          // Custom table names (advanced)
        'field_mapping' => []                           // Custom field names (advanced)
    ]
];
```

## Authless (Public) Server Configuration

For public MCP servers that don't require authentication - perfect for documentation, support, and public tools.

### Basic Authless Setup

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp-public',
    'server_info' => [
        'name' => 'Public Support & Documentation',
        'version' => '1.0.0'
    ],
    'auth' => [
        'authless' => true                              // Disables all authentication
    ]
];

// Simple route - no authentication middleware needed
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp-public', [$mcpProvider, 'handleMCP']);
```

### Authless with Custom Context

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp-public',
    'auth' => [
        'authless' => true,
        'authless_context_data' => [
            'id' => 1,
            'name' => 'Public Documentation Access',
            'type' => 'documentation',
            'permissions' => ['read', 'search']
        ]
    ]
];
```

## Database Configuration

WaaSuP provides three approaches to database integration, from simple to advanced.

### Approach 1: Table Prefixing (Recommended)

Let WaaSuP create its own tables with a prefix. Simplest and most reliable approach.

```php
$config = [
    'database' => [
        'table_prefix' => 'mcp_'                        // Creates: mcp_agencies, mcp_users, etc.
    ]
];
```

### Approach 2: Custom Table Names

Map WaaSuP's logical table names to your existing tables. Use when you have existing data to preserve.

```php
$config = [
    'database' => [
        'table_prefix' => '',                           // Empty since using custom mappings
        'table_mapping' => [
            'agencies' => 'client_organizations',       // Your existing agency table
            'users' => 'app_users',                     // Your existing user table
            'oauth_clients' => 'oauth_applications',    // Your existing OAuth clients
            'oauth_tokens' => 'oauth_access_tokens',    // Your existing OAuth tokens
            // Leave sessions, messages unmapped - these are always new
        ]
    ]
];
```

### Approach 3: Custom Field Names

Map field names when your existing tables use different column names.

```php
$config = [
    'database' => [
        'table_mapping' => [
            'agencies' => 'client_organizations',
            'users' => 'app_users'
        ],
        'field_mapping' => [
            'agencies' => [
                'uuid' => 'organization_uuid',          // Your field -> WaaSuP's expected field
                'name' => 'organization_name',
                'active' => 'is_active'
            ],
            'users' => [
                'uuid' => 'user_guid',
                'agency_id' => 'organization_id',
                'email' => 'email_address'
            ]
        ]
    ]
];
```

### Required Database Schema

If integrating with existing tables, ensure they have these required fields:

**agencies/organizations table:**
- `id` (int, primary key)
- `uuid` (varchar, unique identifier for URLs)
- `name` (varchar)
- `active` (boolean)

**users table:**
- `id` (int, primary key)
- `uuid` (varchar, unique identifier for URLs)
- `agency_id` (int, foreign key to agencies)
- `name` (varchar)
- `email` (varchar, unique)
- `password` (varchar, hashed)

**Optional social auth fields:**
- `google_id` (varchar)
- `linkedin_id` (varchar)
- `github_id` (varchar)

For complete schema reference, see [database-schema.sql](../examples/database/database-schema.sql).

## Transport Configuration

Configure message queue timing and connection behavior.

### Connection Timing Settings

```php
$config = [
    'sse' => [
        'keepalive_interval' => 1,                      // Seconds between keepalive messages
        'max_connection_time' => 1800,                  // 30 minutes max connection
        'switch_interval_after' => 60                   // Increase interval after 1 minute of inactivity
    ],
    'streamable_http' => [                             // For MCP 2025-03-26+
        'keepalive_interval' => 1,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60
    ]
];
```

### Why Configure Timers?

- **keepalive_interval**: Prevents connection timeouts, keeps clients connected
- **max_connection_time**: Prevents resource leaks from abandoned connections
- **switch_interval_after**: Reduces server load when no messages are queued

For high-traffic servers, increase intervals. For real-time applications, decrease them.

## OAuth Server Configuration

### Basic OAuth Setup

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp/{agencyUuid}',        // MCP endpoint
    'oauth' => [
        'base_url' => 'https://yoursite.com/oauth',               // OAuth endpoints base
        'auth_server' => [
            'endpoints' => [
                'authorize' => '/authorize',                       // Full URL: /oauth/authorize
                'token' => '/token',
                'register' => '/register',
                'revoke' => '/revoke'
            ]
        ]
    ]
];
```

### Required OAuth Routes

You must implement these routes in your application:

```php
// OAuth Authorization Server Routes
$app->group('/oauth', function (RouteCollectorProxy $group) {

    $group->get('/authorize', function (Request $request, Response $response) {
        // Initialize OAuthServer with your storage and config
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->authorize($request, $response);
    });

    $group->post('/verify', function (Request $request, Response $response) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->verify($request, $response);
    });

    $group->post('/consent', function (Request $request, Response $response) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->consent($request, $response);
    });

    $group->post('/token', function (Request $request, Response $response) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->token($request, $response);
    });

    $group->post('/revoke', function (Request $request, Response $response) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->revoke($request, $response);
    });

    $group->post('/register', function (Request $request, Response $response) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->register($request, $response);
    });
});
```

### MCP Endpoint with Authentication

```php
// Authenticated MCP endpoint - requires {agencyUuid} in URL
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    function (Request $request, Response $response) {
        // Your MCP server setup...
        return $mcpProvider->handleMCP($request, $response);
    }
)->add($mcpProvider->getAuthMiddleware());  // Add authentication middleware
```

### Social Authentication

```php
$config = [
    'oauth' => [
        'auth_server' => [
            'providers' => [
                'google' => [
                    'client_id' => 'your-google-client-id',
                    'client_secret' => 'your-google-client-secret',
                    'redirect_uri' => 'https://yoursite.com/oauth/verify'
                ],
                'linkedin' => [
                    'client_id' => 'your-linkedin-client-id',
                    'client_secret' => 'your-linkedin-client-secret',
                    'redirect_uri' => 'https://yoursite.com/oauth/verify'
                ],
                'github' => [
                    'client_id' => 'your-github-client-id',
                    'client_secret' => 'your-github-client-secret',
                    'redirect_uri' => 'https://yoursite.com/oauth/verify'
                ]
            ]
        ]
    ]
];
```

## Well-Known Discovery Endpoints

RFC-compliant discovery endpoints for OAuth 2.1 and MCP integration.

### Authorization Server Discovery

```php
// RFC 8414: OAuth 2.0 Authorization Server Metadata
$app->get('/.well-known/oauth-authorization-server[/{path:.*}]',
    function (Request $request, Response $response) {
        $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider($config);
        return $discoveryProvider->authorizationServer($request, $response);
    }
);
```

### Resource Server Discovery

```php
// RFC 9728: OAuth 2.0 Protected Resource Metadata
$app->get('/.well-known/oauth-protected-resource[/{path:.*}]',
    function (Request $request, Response $response) {
        $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider($config);
        return $discoveryProvider->protectedResource($request, $response);
    }
);
```

These endpoints automatically provide OAuth metadata to compliant clients.

## Protocol Version Support

WaaSuP supports multiple MCP protocol versions with automatic feature gating.

```php
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],  // Newest first
];
```

**Version Features:**
- **2024-11-05**: Base MCP protocol (tools, prompts, resources)
- **2025-03-26**: Adds audio content, tool annotations, JSON-RPC batching
- **2025-06-18**: Adds OAuth 2.1 resource indicators, structured outputs, elicitation

The server automatically negotiates the best supported version with each client.

## Multi-Tenant Configuration

For SaaS applications serving multiple organizations:

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp/{agencyUuid}',        // {agencyUuid} in URL
    'auth' => [
        'context_types' => ['agency'],                             // Security context
        'validate_scope' => true,
        'required_scopes' => ['mcp:read', 'mcp:write']
    ]
];
```

The `{agencyUuid}` parameter provides tenant isolation - each agency/organization gets their own isolated MCP server instance.

## Session Management

```php
$config = [
    'session_lifetime' => 3600,                                  // 1 hour sessions
    'session_user_id' => 'user_id'                              // Session key for user validation
];
```

## Complete Configuration Reference

```php
// All available configuration options with defaults
$config = [
    // Core Settings
    'base_url' => null,                                          // Required: Your MCP endpoint
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'session_lifetime' => 3600,
    'session_user_id' => null,
    'test_mode' => false,

    // Server Information
    'server_info' => [
        'name' => 'WaaSuP MCP SaaS Server',
        'version' => '2.0.0'
    ],

    // Authentication
    'auth' => [
        'authless' => false,
        'context_types' => ['agency', 'user'],
        'validate_scope' => true,
        'required_scopes' => ['mcp:read'],
        'authless_context_id' => 'public',
        'authless_context_data' => [
            'id' => 1,
            'name' => 'Public Access',
            'active' => true,
            'type' => 'public'
        ]
    ],

    // OAuth Configuration
    'oauth' => [
        'base_url' => '',
        'auth_server' => [
            'endpoints' => [
                'authorize' => '/oauth/authorize',
                'token' => '/oauth/token',
                'register' => '/oauth/register',
                'revoke' => '/oauth/revoke'
            ],
            'providers' => [
                'google' => ['client_id' => null, 'client_secret' => null, 'redirect_uri' => null],
                'linkedin' => ['client_id' => null, 'client_secret' => null, 'redirect_uri' => null],
                'github' => ['client_id' => null, 'client_secret' => null, 'redirect_uri' => null]
            ]
        ]
    ],

    // Transport Settings
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

    // Database Configuration
    'database' => [
        'table_prefix' => 'mcp_',
        'cleanup_interval' => 3600,
        'table_mapping' => [],
        'field_mapping' => []
    ]
];
```

## Environment-Specific Configuration

### Development
```php
$config = [
    'test_mode' => true,                                         // Faster responses for testing
    'sse' => ['keepalive_interval' => 5],                       // Less aggressive polling
    'session_lifetime' => 86400                                 // 24-hour sessions
];
```

### Production
```php
$config = [
    'sse' => ['keepalive_interval' => 1],                       // Real-time responses
    'session_lifetime' => 3600,                                 // 1-hour sessions
    'database' => ['cleanup_interval' => 1800]                  // Clean up every 30 minutes
];
```

---

Next: Learn about [Authentication](authentication.md) setup →
