# API Reference

[Installation](getting-started.md) | [Configuration](configuration.md) | [Authentication](authentication.md) | [Building Tools](tools.md) | **API Reference**

---

## Get Help Building with WaaSuP

**Live AI-Powered Support**: Connect to `https://seolinkmap.com/mcp-repo` with your AI assistant to get instant help with WaaSuP integration. This public MCP server has access to the entire WaaSuP codebase and can help you with:
- Complete configuration reference and troubleshooting
- API endpoint setup and routing
- Database schema design and field mapping
- Authentication flow implementation
- Protocol version compatibility
- Performance tuning and optimization

**Learn MCP Integration**: Visit [How to Connect to MCP Servers](https://seolinkmap.com/documentation/how-to-connect-to-mcp-servers) for step-by-step instructions on connecting your AI tools to MCP servers.

---

## Overview

WaaSuP uses a comprehensive configuration system that provides sensible defaults for all settings. You only need to configure values that differ from the defaults - the system automatically merges your configuration with the internal defaults.

All configuration is passed as an array to the `MCPSaaSServer` constructor or framework integration classes.

## Complete Configuration Reference

The WaaSuP configuration system is organized into logical sections. Here's the complete configuration array with all available options:

```php
$config = [
    // ================================================================
    // CORE MCP PROTOCOL CONFIGURATION
    // ================================================================
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'base_url' => null,                    // Your MCP server URL (auto-detected if null)
    'session_user_id' => null,             // Session key for existing user login integration
    'scopes_supported' => ['mcp:read', 'mcp:write'],
    'session_lifetime' => 3600,            // MCP session lifetime in seconds
    'test_mode' => false,                  // Set true for testing (disables some features)

    // ================================================================
    // SERVER INFORMATION
    // ================================================================
    'server_info' => [
        'name' => 'WaaSuP MCP SaaS Server',     // Display name for your MCP server
        'version' => '2.0.0'                    // Your server version
    ],

    // ================================================================
    // AUTHENTICATION CONFIGURATION
    // ================================================================
    'auth' => [
        'context_types' => ['agency', 'user'],          // Security context types for multi-tenancy
        'validate_scope' => true,                       // Enforce OAuth scope validation
        'required_scopes' => ['mcp:read'],              // Required OAuth scopes
        'authless' => false,                            // true = public access, false = OAuth required

        // Public/Authless Mode Settings (only used when authless = true)
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

    // ================================================================
    // OAUTH 2.1 CONFIGURATION
    // ================================================================
    'oauth' => [
        'base_url' => '',                               // OAuth server base URL (defaults to same as MCP)

        // Authorization Server Configuration
        'auth_server' => [
            'endpoints' => [
                'authorize' => '/oauth/authorize',       // Authorization endpoint path
                'token' => '/oauth/token',               // Token endpoint path
                'register' => '/oauth/register',         // Dynamic client registration path
                'revoke' => '/oauth/revoke'              // Token revocation path
            ],

            // Social Authentication Providers
            'providers' => [
                'google' => [
                    'client_id' => null,                 // Google OAuth client ID
                    'client_secret' => null,             // Google OAuth client secret
                    'redirect_uri' => null               // OAuth callback URL
                ],
                'linkedin' => [
                    'client_id' => null,                 // LinkedIn OAuth client ID
                    'client_secret' => null,             // LinkedIn OAuth client secret
                    'redirect_uri' => null               // OAuth callback URL
                ],
                'github' => [
                    'client_id' => null,                 // GitHub OAuth client ID
                    'client_secret' => null,             // GitHub OAuth client secret
                    'redirect_uri' => null               // OAuth callback URL
                ]
            ]
        ],

        // Resource Server Configuration (RFC 9728, MCP 2025-06-18)
        'resource_server' => [
            'enabled' => true,                          // Enable RFC 9728 resource server features
            'resource_indicators_supported' => true,   // Support RFC 8707 resource indicators
            'resource_indicator' => null,              // Specific resource indicator (auto-generated if null)
            'metadata_enabled' => true,                // Enable well-known metadata endpoints
            'require_resource_binding' => true         // Require tokens to be bound to resources
        ]
    ],

    // ================================================================
    // TRANSPORT CONFIGURATION
    // ================================================================

    // Server-Sent Events (MCP 2024-11-05)
    'sse' => [
        'keepalive_interval' => 1,                     // Seconds between keepalive messages
        'max_connection_time' => 1800,                 // Maximum connection duration (30 minutes)
        'switch_interval_after' => 60                  // Switch to longer intervals after inactivity
    ],

    // Streamable HTTP (MCP 2025-03-26+)
    'streamable_http' => [
        'keepalive_interval' => 1,                     // Seconds between keepalive messages
        'max_connection_time' => 1800,                 // Maximum connection duration (30 minutes)
        'switch_interval_after' => 60                  // Switch to longer intervals after inactivity
    ],

    // ================================================================
    // DATABASE CONFIGURATION
    // ================================================================
    'database' => [
        'table_prefix' => 'mcp_',                      // Prefix for auto-generated table names
        'cleanup_interval' => 3600,                    // Automatic cleanup frequency (seconds)
        'table_mapping' => [],                         // Map logical table names to existing tables
        'field_mapping' => []                          // Map logical field names to existing columns
    ]
];
```

## Configuration Sections Explained

### Core Protocol Settings

**`supported_versions`**: Array of MCP protocol versions your server supports. Listed in preference order (newest first). The server automatically negotiates the best compatible version with each client.

**`base_url`**: The base URL where your MCP server is accessible. If null, WaaSuP auto-detects from the request. For multi-tenant setups, include the `{agencyUuid}` placeholder.

**`session_user_id`**: Key name in `$_SESSION` for existing user login integration. If your application already has user sessions, WaaSuP can use them to skip OAuth login.

**`test_mode`**: Set to true in test environments to disable long-running SSE/StreamableHTTP connections and return responses immediately. Has nothing to do with authentication - use for faster test execution only.

## Configuration Decision Guide

### Step 1: Choose Your Access Model

**Building a public service?** (documentation, status pages, public APIs)
```php
$config = [
    'auth' => ['authless' => true]
];
```

**Building user/organization-specific tools?** (dashboards, private data)
```php
// Use defaults - no configuration needed for basic OAuth
$config = [
    'oauth' => [
        'auth_server' => [
            'providers' => [
                'google' => [
                    'client_id' => 'your-google-client-id',
                    'client_secret' => 'your-google-client-secret',
                    'redirect_uri' => 'https://yoursite.com/oauth/verify'
                ]
            ]
        ]
    ]
];
```

### Step 2: Choose Your Data Context

**Organization-based SaaS?** (each company gets their own space)
```php
$config = [
    'base_url' => 'https://yoursite.com/mcp/{agencyUuid}',
    'auth' => ['context_types' => ['agency']]
];
```

**User-based application?** (each user gets their own space)
```php
$config = [
    'base_url' => 'https://yoursite.com/mcp/{userUuid}',
    'auth' => ['context_types' => ['user']]
];
```

### Step 3: Database Integration

**New application?** Let WaaSuP create tables:
```php
$config = [
    'database' => ['table_prefix' => 'mcp_']
];
```

**Existing user system?** Map to your tables:
```php
$config = [
    'database' => [
        'table_mapping' => [
            'agencies' => 'your_organizations_table',
            'users' => 'your_users_table'
        ]
    ]
];
```

## When to Use Authless Mode

Use `authless: true` for:
- **Public documentation** - FAQ systems, help documentation, general information
- **Marketing websites** - Product information, company details, public resources
- **Public APIs** - Weather data, news feeds, publicly available information
- **Support systems** - Public troubleshooting tools, status pages

Use authenticated mode (default) for:
- **User-specific data** - Personal dashboards, account information, private files
- **Organization data** - Company-specific metrics, internal tools, customer data
- **Any personalized content** - User preferences, saved states, private information

**Important**: Even in development/testing, if your application needs to access user-specific or organization-specific data, you need authentication. Authless mode has nothing to do with development vs production.

**`auth.authless`**:
- `true`: Public access mode - like a public website, documentation, or FAQ system where anyone can access without authentication
- Default (`false`): Requires OAuth 2.1 authentication for user/organization-specific data

**`auth.context_types`**: Defines which database table to look up the UUID from the URL:
- `['agency']`: Look up context UUID in the agencies table
- `['user']`: Look up context UUID in the users table
- `['agency', 'user']`: Try agencies table first, then users table

**`auth.required_scopes`**: OAuth scopes required for MCP access. Standard scopes:
- `mcp:read`: Read-only access to tools, prompts, resources
- `mcp:write`: Full access including state-changing operations

### OAuth Social Authentication

Configure social login providers by setting their OAuth client credentials:

```php
'oauth' => [
    'auth_server' => [
        'providers' => [
            'google' => [
                'client_id' => 'your-google-oauth-client-id',
                'client_secret' => 'your-google-oauth-client-secret',
                'redirect_uri' => 'https://yoursite.com/oauth/verify'
            ]
        ]
    ]
]
```

### Transport Performance Tuning

Both SSE and Streamable HTTP transports use adaptive polling:

**`keepalive_interval`**: How often to send keepalive messages (seconds). Lower values = more responsive, higher server load.

**`max_connection_time`**: Maximum connection duration before automatic disconnect. Prevents resource leaks from abandoned connections.

**`switch_interval_after`**: After this many seconds of inactivity, polling intervals automatically increase to reduce server load.

## Database Configuration Reference

WaaSuP provides flexible database integration through table and field mapping. Here's the complete database configuration structure:

```php
$config = [
    'database' => [
        // ================================================================
        // BASIC DATABASE SETTINGS
        // ================================================================
        'table_prefix' => 'mcp_',                      // Prefix for default table names
        'cleanup_interval' => 3600,                    // Cleanup expired data every hour
        'table_mapping' => [],                         // Map logical names to your existing tables

        // ================================================================
        // COMPLETE FIELD MAPPING REFERENCE
        // ================================================================
        'field_mapping' => [
            // Agency/Organization Table Fields
            'agencies' => [
                'id' => 'id',                           // Primary key (integer)
                'uuid' => 'uuid',                       // Unique identifier for URLs (varchar)
                'name' => 'name',                       // Organization name (varchar)
                'active' => 'active'                    // Active status (boolean)
            ],

            // User Account Table Fields
            'users' => [
                'id' => 'id',                           // Primary key (integer)
                'uuid' => 'uuid',                       // Unique identifier for URLs (varchar)
                'agency_id' => 'agency_id',             // Foreign key to agencies (integer)
                'name' => 'name',                       // User display name (varchar)
                'email' => 'email',                     // Email address (varchar, unique)
                'password' => 'password',               // Hashed password (varchar)
                'google_id' => 'google_id',             // Google OAuth ID (varchar, nullable)
                'linkedin_id' => 'linkedin_id',         // LinkedIn OAuth ID (varchar, nullable)
                'github_id' => 'github_id'              // GitHub OAuth ID (varchar, nullable)
            ],

            // OAuth Client Registration Table Fields
            'oauth_clients' => [
                'client_id' => 'client_id',             // OAuth client identifier (varchar)
                'client_secret' => 'client_secret',     // OAuth client secret (varchar, nullable)
                'client_name' => 'client_name',         // Human-readable client name (varchar)
                'redirect_uris' => 'redirect_uris',     // JSON array of allowed redirect URIs (text)
                'grant_types' => 'grant_types',         // JSON array of allowed grant types (text)
                'response_types' => 'response_types',   // JSON array of allowed response types (text)
                'created_at' => 'created_at'            // Registration timestamp (datetime)
            ],

            // OAuth Token Storage Table Fields
            'oauth_tokens' => [
                'client_id' => 'client_id',             // OAuth client identifier (varchar)
                'access_token' => 'access_token',       // Access token value (varchar, unique)
                'refresh_token' => 'refresh_token',     // Refresh token value (varchar, nullable)
                'token_type' => 'token_type',           // Token type (varchar, usually 'Bearer')
                'scope' => 'scope',                     // Granted scopes (varchar)
                'expires_at' => 'expires_at',           // Token expiration (datetime)
                'agency_id' => 'agency_id',             // Bound agency (integer)
                'user_id' => 'user_id',                 // Token owner (integer)
                'resource' => 'resource',               // RFC 8707 resource binding (varchar, nullable)
                'aud' => 'aud',                         // Audience claim JSON array (text, nullable)
                'revoked' => 'revoked',                 // Revocation status (boolean)
                'created_at' => 'created_at',           // Token creation timestamp (datetime)
                'code_challenge' => 'code_challenge',   // PKCE code challenge (varchar, nullable)
                'code_challenge_method' => 'code_challenge_method' // PKCE method (varchar, nullable)
            ],

            // MCP Session Storage Table Fields
            'sessions' => [
                'session_id' => 'session_id',           // MCP session identifier (varchar, unique)
                'session_data' => 'session_data',       // JSON session data (text)
                'expires_at' => 'expires_at',           // Session expiration (datetime)
                'created_at' => 'created_at'            // Session creation timestamp (datetime)
            ],

            // MCP Message Queue Table Fields
            'messages' => [
                'id' => 'id',                           // Primary key (integer, auto-increment)
                'session_id' => 'session_id',           // MCP session identifier (varchar)
                'message_data' => 'message_data',       // JSON-RPC message data (text)
                'context_data' => 'context_data',       // Context information (text)
                'created_at' => 'created_at'            // Message timestamp (datetime)
            ],

            // MCP Sampling Response Storage Table Fields
            'sampling_responses' => [
                'id' => 'id',                           // Primary key (integer, auto-increment)
                'session_id' => 'session_id',           // MCP session identifier (varchar)
                'request_id' => 'request_id',           // Sampling request identifier (varchar)
                'response_data' => 'response_data',     // JSON response data (text)
                'created_at' => 'created_at'            // Response timestamp (datetime)
            ],

            // MCP Roots Response Storage Table Fields
            'roots_responses' => [
                'id' => 'id',                           // Primary key (integer, auto-increment)
                'session_id' => 'session_id',           // MCP session identifier (varchar)
                'request_id' => 'request_id',           // Roots request identifier (varchar)
                'response_data' => 'response_data',     // JSON response data (text)
                'created_at' => 'created_at'            // Response timestamp (datetime)
            ],

            // MCP Elicitation Response Storage Table Fields (2025-06-18+)
            'elicitation_responses' => [
                'id' => 'id',                           // Primary key (integer, auto-increment)
                'session_id' => 'session_id',           // MCP session identifier (varchar)
                'request_id' => 'request_id',           // Elicitation request identifier (varchar)
                'response_data' => 'response_data',     // JSON response data (text)
                'created_at' => 'created_at'            // Response timestamp (datetime)
            ]
        ]
    ]
];
```

## Database Integration Approaches

### Approach 1: Use Default Tables (Recommended)

Let WaaSuP create its own tables with optional prefixing:

```php
$config = [
    'database' => [
        'table_prefix' => 'mcp_'  // Creates: mcp_agencies, mcp_users, etc.
    ]
];
```

### Approach 2: Map to Existing Tables

Map WaaSuP's logical table names to your existing tables:

```php
$config = [
    'database' => [
        'table_prefix' => '',  // Empty since using custom mappings
        'table_mapping' => [
            'agencies' => 'client_organizations',    // Your existing organization table
            'users' => 'app_users',                  // Your existing user table
            'oauth_clients' => 'oauth_applications', // Your existing OAuth clients
            // Leave sessions, messages unmapped - these are always new
        ]
    ]
];
```

### Approach 3: Custom Field Names

Map field names when your existing tables use different column names:

```php
$config = [
    'database' => [
        'table_mapping' => [
            'agencies' => 'client_organizations'
        ],
        'field_mapping' => [
            'agencies' => [
                'uuid' => 'organization_uuid',       // Your field -> WaaSuP expected field
                'name' => 'organization_name',
                'active' => 'is_active'
            ]
        ]
    ]
];
```

## Protocol Version Features

WaaSuP automatically gates features based on the negotiated MCP protocol version:

| Feature | 2024-11-05 | 2025-03-26 | 2025-06-18 |
|---------|------------|------------|------------|
| Tools, Prompts, Resources | ✅ | ✅ | ✅ |
| Progress Notifications | ✅ | ✅ | ✅ |
| Tool Annotations | ❌ | ✅ | ✅ |
| Audio Content | ❌ | ✅ | ✅ |
| JSON-RPC Batching | ❌ | ✅ | ❌ |
| Completions | ❌ | ✅ | ✅ |
| Elicitation | ❌ | ❌ | ✅ |
| Structured Outputs | ❌ | ❌ | ✅ |
| Resource Links | ❌ | ❌ | ✅ |
| OAuth Resource Indicators | ❌ | ❌ | ✅ |

## Environment-Specific Configuration

### Development Configuration

```php
$config = [
    'test_mode' => true,                     // Faster test execution - kills connections quickly
    'session_lifetime' => 86400,            // 24-hour sessions for easier debugging
    'auth' => ['authless' => true],          // ONLY if building public tools (documentation, etc.)
    'sse' => ['keepalive_interval' => 5],    // Less aggressive polling during development
];
```

### Production Configuration

```php
$config = [
    'session_lifetime' => 3600,                     // 1-hour sessions for security
    'sse' => ['keepalive_interval' => 1],           // Real-time responses
    'database' => ['cleanup_interval' => 1800],     // Clean up every 30 minutes
    'oauth' => [
        'auth_server' => [
            'providers' => [
                'google' => [
                    'client_id' => env('GOOGLE_CLIENT_ID'),
                    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                    'redirect_uri' => env('APP_URL') . '/oauth/verify'
                ]
            ]
        ]
    ]
];
```

## Configuration Validation

WaaSuP validates configuration values and provides helpful error messages for common issues:

- Invalid protocol versions are rejected with supported alternatives
- Missing OAuth credentials show setup instructions
- Database connection errors include troubleshooting guidance
- Invalid field mappings suggest correct table structures

## Performance Considerations

### High-Traffic Servers

For servers handling many concurrent connections:

```php
$config = [
    'sse' => [
        'keepalive_interval' => 2,          // Reduce polling frequency
        'max_connection_time' => 900,       // Shorter max connection time
        'switch_interval_after' => 30       // Switch to longer intervals faster
    ],
    'database' => [
        'cleanup_interval' => 1800          // More frequent cleanup
    ]
];
```

### Real-Time Applications

For applications requiring immediate responsiveness:

```php
$config = [
    'sse' => [
        'keepalive_interval' => 1,          // Maximum responsiveness
        'max_connection_time' => 3600,      // Longer connections allowed
        'switch_interval_after' => 120      // Stay responsive longer
    ]
];
```

---

Connect to `https://seolinkmap.com/mcp-repo` with your AI assistant for live help with configuration, troubleshooting, and integration guidance.
