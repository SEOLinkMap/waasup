# Authentication & Security

[Installation](getting-started.md) | [Configuration](configuration.md) | **Authentication** | [Building Tools](tools.md) | [API Reference](api-reference.md)

---

## Get Help Building with WaaSuP

**Live AI-Powered Support**: Connect to `https://seolinkmap.com/mcp-repo` with your AI assistant to get instant help with WaaSuP authentication setup. This public MCP server has access to the entire WaaSuP codebase and can help you with:
- OAuth 2.1 configuration and troubleshooting
- Multi-tenant security implementation
- Database integration with existing user systems
- Authentication flow debugging
- RFC compliance validation

**Learn MCP Integration**: Visit [How to Connect to MCP Servers](https://seolinkmap.com/documentation/how-to-connect-to-mcp-servers) for step-by-step instructions on connecting your AI tools to MCP servers.

---

## Overview

WaaSuP provides two distinct authentication modes to fit different use cases:

1. **Authless (Public) Mode** - Like a public website, anyone can access your MCP server
2. **Private (OAuth) Mode** - Multi-tenant system with agency/user isolation and OAuth 2.1 authentication

The library implements full **RFC compliance** including:
- **RFC 8414**: OAuth 2.0 Authorization Server Metadata
- **RFC 9728**: OAuth 2.0 Protected Resource Metadata
- **RFC 8707**: OAuth 2.0 Resource Indicators (MCP 2025-06-18)
- **OAuth 2.1** specification with PKCE requirements

## Authentication Modes

### Authless (Public) Mode

Perfect for documentation, support, FAQs, and public services - anyone can access your MCP server without authentication.

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp-public',
    'auth' => [
        'authless' => true  // Disables all authentication
    ]
];

// Simple route - no authentication middleware needed
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp-public', [$mcpProvider, 'handleMCP']);
```

**Security Note**: Authless mode provides a default public context. Only expose tools and data that are safe for public consumption.

### Private (OAuth) Mode

Multi-tenant system with agency-level or user-level isolation. Each organization gets their own isolated MCP server instance.

```php
$config = [
    'base_url' => 'https://yoursite.com/mcp/{agencyUuid}',        // Tenant isolation via URL
    'auth' => [
        'authless' => false,                                      // OAuth required (default)
        'context_types' => ['agency'],                            // or ['user'] or ['agency', 'user']
        'validate_scope' => true,
        'required_scopes' => ['mcp:read', 'mcp:write']
    ],
    'oauth' => [
        'base_url' => 'https://yoursite.com/oauth'
    ]
];

// Private endpoint with OAuth authentication
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());  // Requires authentication
```

**Multi-Tenant Security**: The `{agencyUuid}` in the URL provides tenant isolation. Each agency/organization can only access their own data.

## Database Integration

WaaSuP works with your existing user and organization tables. You just need to ensure the required fields exist.

### Approach 1: Use Your Existing Tables (Recommended)

Map WaaSuP to your existing database structure:

```php
$config = [
    'database' => [
        'table_prefix' => '',  // Empty since using custom mappings
        'table_mapping' => [
            'agencies' => 'client_organizations',    // Your existing organization table
            'users' => 'app_users',                  // Your existing user table
            'oauth_clients' => 'oauth_applications', // Your existing OAuth clients
            'oauth_tokens' => 'oauth_access_tokens', // Your existing OAuth tokens
            // sessions, messages use default names (these are always new)
        ],
        'field_mapping' => [
            'agencies' => [
                'uuid' => 'organization_uuid',       // Your field -> WaaSuP expected field
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

### Required Database Fields

**For agencies/organizations table:**
- `id` (int, primary key)
- `uuid` (varchar, unique identifier used in URLs)
- `name` (varchar)
- `active` (boolean)

**For users table:**
- `id` (int, primary key)
- `uuid` (varchar, unique identifier used in URLs)
- `agency_id` (int, foreign key to agencies)
- `name` (varchar)
- `email` (varchar, unique)
- `password` (varchar, hashed)

**Optional social auth fields:**
- `google_id`, `linkedin_id`, `github_id` (varchar)

### Approach 2: Let WaaSuP Create Tables

If you don't have existing user management, let WaaSuP create its own tables:

```php
$config = [
    'database' => [
        'table_prefix' => 'mcp_'  // Creates: mcp_agencies, mcp_users, etc.
    ]
];
```

## OAuth 2.1 Server Setup

WaaSuP includes a complete OAuth 2.1 authorization server with social authentication support.

### Required OAuth Routes

```php
// OAuth Authorization Server Routes
$app->group('/oauth', function (RouteCollectorProxy $group) use ($mcpProvider) {

    // Authorization endpoint (where users go to login)
    $group->get('/authorize', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->authorize($request, $response);
    });

    // User verification (login form handling)
    $group->post('/verify', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->verify($request, $response);
    });

    // Consent screen handling
    $group->post('/consent', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->consent($request, $response);
    });

    // Token endpoint (authorization code exchange)
    $group->post('/token', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->token($request, $response);
    });

    // Token revocation
    $group->post('/revoke', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->revoke($request, $response);
    });

    // Dynamic client registration
    $group->post('/register', function (Request $request, Response $response) use ($mcpProvider) {
        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage, $responseFactory, $streamFactory, $config
        );
        return $oauthServer->register($request, $response);
    });
});
```

### Social Authentication Setup

Configure social providers for easier user login:

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

## RFC-Compliant Discovery Endpoints

WaaSuP automatically provides standards-compliant discovery endpoints for OAuth metadata.

### Authorization Server Discovery (RFC 8414)

```php
$app->get('/.well-known/oauth-authorization-server[/{path:.*}]',
    function (Request $request, Response $response) use ($config) {
        $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider($config);
        return $discoveryProvider->authorizationServer($request, $response);
    }
);
```

### Resource Server Discovery (RFC 9728)

```php
$app->get('/.well-known/oauth-protected-resource[/{path:.*}]',
    function (Request $request, Response $response) use ($config) {
        $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider($config);
        return $discoveryProvider->protectedResource($request, $response);
    }
);
```

These endpoints provide automatic OAuth metadata discovery for compliant clients.

## Multi-Tenant Security Architecture

### Agency-Level Isolation

Each organization gets its own isolated MCP server instance via URL-based routing:

```
https://yoursite.com/mcp/550e8400-e29b-41d4-a716-446655440000  # Agency A
https://yoursite.com/mcp/6ba7b810-9dad-11d1-80b4-00c04fd430c8  # Agency B
```

**Security Features:**
- OAuth tokens are bound to specific agencies
- Database queries automatically filter by agency context
- Cross-agency data access is impossible
- Session isolation per agency

### User-Level Context

For user-specific tools and data access:

```php
$config = [
    'auth' => [
        'context_types' => ['user'],  // User-level instead of agency-level
    ]
];

// URL becomes: https://yoursite.com/mcp/{userUuid}
```

### Context Access in Tools

Access the authenticated context in your tools:

```php
$toolRegistry->register('get_customer_data', function($params, $context) {
    // $context contains authenticated agency/user information
    $agencyId = $context['context_data']['id'];
    $userId = $context['token_data']['user_id'];

    // Your tool can safely access data for this agency/user only
    return getCustomerDataForAgency($agencyId);
}, [
    'description' => 'Get customer data for authenticated organization'
]);
```

## Session Management

Configure session behavior for your application:

```php
$config = [
    'session_lifetime' => 3600,          // 1 hour MCP sessions
    'session_user_id' => 'user_id'       // Key name in $_SESSION for user validation
];
```

**Session Integration**: If a user is already logged into your application, WaaSuP can use that session to skip the OAuth login process.

## Resource Indicators (MCP 2025-06-18)

For the latest MCP protocol version, WaaSuP supports RFC 8707 Resource Indicators for enhanced security:

```php
// OAuth tokens are bound to specific MCP resource URLs
// This prevents token misuse across different resource servers
$expectedResource = 'https://yoursite.com/mcp/agency-uuid';

// WaaSuP automatically validates:
// - Token was issued for this specific resource
// - Audience claim matches the resource server
// - Scope is appropriate for the requested operations
```

## Integration Examples

### Laravel Integration

```php
// routes/web.php
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;

// Public endpoint
Route::match(['GET', 'POST', 'OPTIONS'], '/mcp-public',
    [LaravelMCPProvider::class, 'handleMCP']
);

// Private endpoint with authentication
Route::middleware('mcp.auth')->group(function () {
    Route::match(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}/{sessID?}',
        [LaravelMCPProvider::class, 'handleMCP']
    );
});
```

### Existing User System Integration

If you already have user authentication, integrate it with WaaSuP:

```php
// Check if user is already logged in
$config = [
    'session_user_id' => 'user_id',  // Your session key for logged-in users
    'database' => [
        'table_mapping' => [
            'users' => 'your_users_table',
            'agencies' => 'your_organizations_table'
        ]
    ]
];

// WaaSuP will automatically use existing login sessions
// and map to your existing user/organization data
```

## Security Best Practices

### Production Security Checklist

- ✅ Use HTTPS for all OAuth endpoints
- ✅ Set secure OAuth client credentials
- ✅ Configure proper CORS headers
- ✅ Validate agency/user context in all tools
- ✅ Use database transactions for multi-table operations
- ✅ Implement proper session timeout handling
- ✅ Monitor OAuth token usage and revocation

### Development vs Production

**Development:**
```php
$config = [
    'test_mode' => true,                    // Faster responses
    'session_lifetime' => 86400,           // 24-hour sessions
    'auth' => ['authless' => true]          // Public access for testing
];
```

**Production:**
```php
$config = [
    'test_mode' => false,                   // Full security
    'session_lifetime' => 3600,            // 1-hour sessions
    'auth' => ['authless' => false],        // Require OAuth
    'oauth' => [
        'auth_server' => [
            'providers' => [/* social auth */]
        ]
    ]
];
```

## Troubleshooting Authentication

### Common Issues

**"Authentication required" errors:**
- Verify OAuth endpoints are properly configured
- Check that authentication middleware is added to routes
- Ensure database tables have required fields

**Token validation failures:**
- Verify agency/user UUIDs match between URL and database
- Check token expiration and revocation status
- Validate OAuth client configuration

**Multi-tenant data leaks:**
- Always check `$context['context_data']['id']` in tools
- Verify database queries filter by agency/user context
- Test with multiple agencies to ensure isolation

### Getting Help

Connect your AI assistant to `https://seolinkmap.com/mcp-repo` for live troubleshooting assistance with authentication setup, OAuth configuration, and multi-tenant security implementation.

---

Next: Learn about [Building Tools](tools.md) →
