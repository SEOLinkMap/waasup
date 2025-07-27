# Authentication & OAuth

The MCP SaaS Server implements OAuth 2.1 with multi-tenant context-based authentication. This document covers the complete authentication flow, token management, and security implementation.

## Overview

The authentication system provides:

- **OAuth 2.1 compliance** with authorization code flow and PKCE requirement
- **RFC 8707 Resource Indicators** for token binding to specific MCP endpoints (2025-06-18)
- **Multi-tenant architecture** with agency-based contexts
- **Social provider integration** (Google, LinkedIn, GitHub)
- **Discovery endpoints** for OAuth configuration
- **PSR-15 middleware** for request authentication
- **Flexible storage** supporting database and memory backends

### Authentication Flow

1. Client discovers OAuth endpoints via `.well-known/oauth-authorization-server`
2. Client redirects user to authorization endpoint with resource parameter (2025-06-18)
3. User authenticates via email/password or social providers
4. User grants consent for specific agency context
5. Server returns authorization code with resource binding
6. Client exchanges code for access token with resource validation
7. Client uses token for MCP API requests with context and resource validation

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

### Custom OAuth Endpoint Configuration

#### Overview

By default, the MCP SaaS Server uses standard `/oauth/*` paths for OAuth endpoints. To avoid conflicts with existing OAuth systems on your server, you can configure custom paths.

#### Configuration Structure

Configure custom OAuth endpoints through the discovery configuration:

```php
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-oauth/authorize',     // Custom authorization endpoint
            'token' => '/mcp-oauth/token',             // Custom token endpoint
            'register' => '/mcp-oauth/register',       // Custom registration endpoint
            'revoke' => '/mcp-oauth/revoke',           // Custom revocation endpoint
            'resource' => '/mcp-oauth/resource'        // Custom resource endpoint (2025-06-18)
        ]
    ],
    'auth' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-oauth/authorize',     // Must match discovery config
            'token' => '/mcp-oauth/token',             // Must match discovery config
            'register' => '/mcp-oauth/register',       // Must match discovery config
            'revoke' => '/mcp-oauth/revoke',           // Must match discovery config
            'resource' => '/mcp-oauth/resource'        // Must match discovery config
        ]
    ]
];
```

#### Implementation Example

```php
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

// Configure custom OAuth paths to avoid conflicts with existing OAuth
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/api/v2/mcp/authorize',
            'token' => '/api/v2/mcp/token',
            'register' => '/api/v2/mcp/register',
            'revoke' => '/api/v2/mcp/revoke'
        ]
    ],
    'auth' => [
        'oauth_endpoints' => [
            'authorize' => '/api/v2/mcp/authorize',
            'token' => '/api/v2/mcp/token',
            'register' => '/api/v2/mcp/register',
            'revoke' => '/api/v2/mcp/revoke'
        ],
        'context_types' => ['agency'],
        'base_url' => 'https://your-server.com'
    ]
];

// Register CUSTOM OAuth routes (not the default /oauth/* paths)
$app->get('/api/v2/mcp/authorize', [$oauthServer, 'authorize']);
$app->post('/api/v2/mcp/verify', [$oauthServer, 'verify']);
$app->post('/api/v2/mcp/consent', [$oauthServer, 'consent']);
$app->post('/api/v2/mcp/token', [$oauthServer, 'token']);
$app->post('/api/v2/mcp/revoke', [$oauthServer, 'revoke']);
$app->post('/api/v2/mcp/register', [$oauthServer, 'register']);
```

#### Common Use Cases

**Avoiding Conflicts with Existing OAuth:**
```php
// Your existing OAuth system uses /oauth/*
// Configure MCP to use completely different paths
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/mcp-auth/authorize',
            'token' => '/mcp-auth/token',
            'register' => '/mcp-auth/register'
        ]
    ]
];
```

**API Versioning:**
```php
// Use versioned API paths
$config = [
    'discovery' => [
        'oauth_endpoints' => [
            'authorize' => '/api/v3/oauth/authorize',
            'token' => '/api/v3/oauth/token',
            'register' => '/api/v3/oauth/register'
        ]
    ]
];
```


### Authorization Endpoint

The authorization endpoint handles user authentication and consent with RFC 8707 Resource Indicators:

```php
// GET /oauth/authorize?response_type=code&client_id=...&redirect_uri=...&scope=...&state=...&resource=...
public function handleAuthorize(Request $request, Response $response): Response
{
    return $this->oauthServer->authorize($request, $response);
}
```

**MCP 2025-06-18 requires resource parameter:**
```
https://server.com/oauth/authorize?
  response_type=code&
  client_id=your_client_id&
  redirect_uri=https://your-app.com/callback&
  scope=mcp:read+mcp:write&
  state=random_state&
  resource=https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000
```

This will:
1. Validate the OAuth parameters including resource URL
2. Check if user is already authenticated
3. Render authentication form with social provider options
4. Handle consent after authentication
5. Bind authorization code to specific resource

### Authentication Verification

Users can authenticate via email/password or social providers:

```php
// POST /oauth/verify
public function handleVerify(Request $request, Response $response): Response
{
    return $this->oauthServer->verify($request, $response);
}
```

**Email/Password Authentication:**
```html
<form method="POST" action="/oauth/verify">
    <input type="email" name="email" required>
    <input type="password" name="password" required>
    <button type="submit" name="provider" value="email">Sign In</button>
</form>
```

**Social Authentication:**
```html
<form method="POST" action="/oauth/verify">
    <button type="submit" name="provider" value="google">Continue with Google</button>
    <button type="submit" name="provider" value="linkedin">Continue with LinkedIn</button>
    <button type="submit" name="provider" value="github">Continue with GitHub</button>
</form>
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

The consent screen displays:
- Application name requesting access
- User information (name, email)
- Requested permissions/scope
- Resource being accessed (for 2025-06-18)

### Token Exchange

Exchange authorization code for access token with resource binding:

```php
// POST /oauth/token
public function handleToken(Request $request, Response $response): Response
{
    return $this->oauthServer->token($request, $response);
}
```

**Authorization Code Grant (with RFC 8707):**
```bash
curl -X POST https://server.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=authorization_code" \
  -d "code=auth_code_here" \
  -d "client_id=your_client_id" \
  -d "redirect_uri=https://your-app.com/callback" \
  -d "code_verifier=pkce_verifier" \
  -d "resource=https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000"
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def50200...",
  "scope": "mcp:read mcp:write"
}
```

The token includes resource binding in database:
```sql
INSERT INTO mcp_oauth_tokens (
    access_token, resource, aud, agency_id, scope
) VALUES (
    'token...',
    'https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000',
    '["https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000"]',
    1,
    'mcp:read mcp:write'
);
```

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

The PSR-15 middleware handles request authentication automatically with RFC 8707 validation.

### Middleware Setup

```php
use Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware;

$authMiddleware = new AuthMiddleware(
    $storage,
    $responseFactory,
    $streamFactory,
    [
        'context_types' => ['agency'],
        'base_url' => 'https://your-server.com',
        'resource_server_metadata' => true,        // OAuth Resource Server (2025-06-18)
        'require_resource_binding' => true,        // RFC 8707 compliance
        'audience_validation_required' => true     // Token audience validation
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
4. Validates token against storage with resource binding (2025-06-18)
5. Validates audience claims and scope checking
6. Adds context data to request attributes
7. Returns OAuth discovery response if authentication fails

### RFC 8707 Resource Indicators Validation (2025-06-18)

For MCP 2025-06-18, the middleware performs strict resource validation:

```php
private function validateResourceServerRequirements(Request $request, array $tokenData): void
{
    $baseUrl = $this->getBaseUrl($request);
    $contextId = $this->extractContextId($request);
    $expectedResource = $baseUrl . '/mcp/' . $contextId;

    // Token must be bound to this specific resource
    if (!isset($tokenData['resource']) || $tokenData['resource'] !== $expectedResource) {
        throw new AuthenticationException('Token not bound to this resource (RFC 8707 violation)');
    }

    // Audience validation prevents token mis-redemption
    if (!isset($tokenData['aud']) || !in_array($expectedResource, (array)$tokenData['aud'])) {
        throw new AuthenticationException('Token audience validation failed');
    }

    if (isset($tokenData['scope']) && !$this->validateTokenScope($tokenData['scope'])) {
        throw new AuthenticationException('Token scope invalid for this resource server');
    }
}
```

### Token Validation

Token validation is handled directly through the storage interface:

```php
// In DatabaseStorage implementation
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

The `WellKnownProvider` implements OAuth discovery as per RFC 8414 with MCP-specific extensions.

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

**Response for 2025-06-18:**
```json
{
  "issuer": "https://server.com",
  "authorization_endpoint": "https://server.com/oauth/authorize",
  "token_endpoint": "https://server.com/oauth/token",
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "response_types_supported": ["code"],
  "token_endpoint_auth_methods_supported": ["client_secret_post", "private_key_jwt", "none"],
  "code_challenge_methods_supported": ["S256"],
  "response_modes_supported": ["query"],
  "registration_endpoint": "https://server.com/oauth/register",
  "scopes_supported": ["mcp:read", "mcp:write"],
  "resource_indicators_supported": true,
  "token_binding_methods_supported": ["resource_indicator"],
  "require_resource_parameter": true,
  "pkce_required": true,
  "authorization_response_iss_parameter_supported": true
}
```

### OAuth Protected Resource Metadata (2025-06-18)

```php
// GET /.well-known/oauth-protected-resource
public function handleResourceDiscovery(Request $request, Response $response): Response
{
    return $wellKnownProvider->protectedResource($request, $response);
}
```

**Response:**
```json
{
  "resource": "https://server.com",
  "authorization_servers": ["https://server.com"],
  "bearer_methods_supported": ["header"],
  "scopes_supported": ["mcp:read", "mcp:write"],
  "resource_server": true,
  "resource_indicators_supported": true,
  "token_binding_supported": true,
  "audience_validation_required": true,
  "resource_indicator_endpoint": "https://server.com/oauth/resource",
  "token_binding_methods_supported": ["resource_indicator"],
  "content_types_supported": ["application/json", "text/event-stream"],
  "mcp_features_supported": [
    "tools", "prompts", "resources", "sampling", "roots", "ping",
    "progress_notifications", "tool_annotations", "audio_content",
    "completions", "elicitation", "structured_outputs", "resource_links"
  ]
}
```

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
    $tokenData = $context['token_data'];

    // Use agency data for business logic
    $agencyId = $agencyData['id'];
    $agencySettings = json_decode($agencyData['settings'], true);

    // Resource validation for 2025-06-18
    if ($context['protocol_version'] === '2025-06-18') {
        $expectedResource = $context['base_url'] . '/mcp/' . $context['context_id'];
        if ($tokenData['resource'] !== $expectedResource) {
            throw new AuthenticationException('Token resource mismatch');
        }
    }

    // Implement agency-specific rate limiting, feature access, etc.
}
```

## Social Authentication Providers

The system includes built-in social authentication providers.

### Google Provider

```php
use Seolinkmap\Waasup\Auth\Providers\GoogleProvider;

$googleProvider = new GoogleProvider(
    'your-google-client-id',
    'your-google-client-secret',
    'https://your-server.com/oauth/google/callback'
);

// Get authorization URL
$authUrl = $googleProvider->getAuthUrl();

// Handle callback
$result = $googleProvider->handleCallback($authorizationCode);
// Returns: ['provider' => 'google', 'provider_id' => '...', 'email' => '...', 'name' => '...']
```

### LinkedIn Provider

```php
use Seolinkmap\Waasup\Auth\Providers\LinkedinProvider;

$linkedinProvider = new LinkedinProvider(
    'your-linkedin-client-id',
    'your-linkedin-client-secret',
    'https://your-server.com/oauth/linkedin/callback'
);

// Get authorization URL with state
$state = bin2hex(random_bytes(16));
$authUrl = $linkedinProvider->getAuthUrl($state);

// Handle callback
$result = $linkedinProvider->handleCallback($authorizationCode, $state);
```

### GitHub Provider

```php
use Seolinkmap\Waasup\Auth\Providers\GithubProvider;

$githubProvider = new GithubProvider(
    'your-github-client-id',
    'your-github-client-secret',
    'https://your-server.com/oauth/github/callback'
);

// Get authorization URL with state
$state = bin2hex(random_bytes(16));
$authUrl = $githubProvider->getAuthUrl($state);

// Handle callback
$result = $githubProvider->handleCallback($authorizationCode, $state);
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

    // Social auth (optional methods - checked with method_exists)
    public function findUserByGoogleId(string $googleId): ?array;
    public function findUserByLinkedinId(string $linkedinId): ?array;
    public function findUserByGithubId(string $githubId): ?array;
    public function updateUserGoogleId(int $userId, string $googleId): bool;
    public function updateUserLinkedinId(int $userId, string $linkedinId): bool;
    public function updateUserGithubId(int $userId, string $githubId): bool;
}
```

## Integration Examples

### Slim Framework Integration

```php
use Slim\Factory\AppFactory;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Auth\OAuthServer;

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

// Create OAuth server
$oauthServer = new OAuthServer($storage, $responseFactory, $streamFactory, $oauthConfig);

// OAuth discovery endpoints
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);
$app->get('/.well-known/oauth-protected-resource',
    [$mcpProvider, 'handleResourceDiscovery']);

// OAuth endpoints
$app->get('/oauth/authorize', [$oauthServer, 'authorize']);
$app->post('/oauth/verify', [$oauthServer, 'verify']);
$app->post('/oauth/consent', [$oauthServer, 'consent']);
$app->post('/oauth/token', [$oauthServer, 'token']);
$app->post('/oauth/revoke', [$oauthServer, 'revoke']);
$app->post('/oauth/register', [$oauthServer, 'register']);

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
// OAuth 2.1 flow with RFC 8707 Resource Indicators
class MCPClient {
    constructor(config) {
        this.config = config;
        this.accessToken = null;
        this.refreshToken = null;
    }

    async authenticate(agencyId, protocolVersion = '2025-06-18') {
        const resource = `${this.config.mcpEndpoint}/${agencyId}`;

        // Generate PKCE parameters
        const codeVerifier = this.generateCodeVerifier();
        const codeChallenge = await this.generateCodeChallenge(codeVerifier);

        // Store for token exchange
        sessionStorage.setItem('code_verifier', codeVerifier);

        // Redirect to authorization endpoint
        const authUrl = new URL(this.config.authorizationEndpoint);
        authUrl.searchParams.set('response_type', 'code');
        authUrl.searchParams.set('client_id', this.config.clientId);
        authUrl.searchParams.set('redirect_uri', this.config.redirectUri);
        authUrl.searchParams.set('scope', 'mcp:read mcp:write');
        authUrl.searchParams.set('state', this.generateState());
        authUrl.searchParams.set('code_challenge', codeChallenge);
        authUrl.searchParams.set('code_challenge_method', 'S256');

        // RFC 8707 Resource Indicators (required for 2025-06-18)
        if (protocolVersion === '2025-06-18') {
            authUrl.searchParams.set('resource', resource);
        }

        window.location.href = authUrl.toString();
    }

    async exchangeCodeForToken(code, agencyId, protocolVersion = '2025-06-18') {
        const codeVerifier = sessionStorage.getItem('code_verifier');
        sessionStorage.removeItem('code_verifier');

        const body = new URLSearchParams({
            grant_type: 'authorization_code',
            code: code,
            client_id: this.config.clientId,
            redirect_uri: this.config.redirectUri,
            code_verifier: codeVerifier
        });

        // Add resource parameter for 2025-06-18
        if (protocolVersion === '2025-06-18') {
            const resource = `${this.config.mcpEndpoint}/${agencyId}`;
            body.append('resource', resource);
        }

        const response = await fetch(this.config.tokenEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });

        if (!response.ok) {
            throw new Error(`Token exchange failed: ${response.status}`);
        }

        const tokens = await response.json();
        this.accessToken = tokens.access_token;
        this.refreshToken = tokens.refresh_token;
        return tokens;
    }

    async callMCP(agencyId, method, params = {}, protocolVersion = '2025-06-18') {
        const headers = {
            'Authorization': `Bearer ${this.accessToken}`,
            'Content-Type': 'application/json'
        };

        // Add protocol version header for 2025-06-18
        if (protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = protocolVersion;
        }

        const response = await fetch(`${this.config.mcpEndpoint}/${agencyId}`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
                jsonrpc: '2.0',
                method: method,
                params: params,
                id: Math.random()
            })
        });

        if (response.status === 401) {
            // Handle resource binding errors
            const error = await response.json();
            if (error.error?.message?.includes('resource')) {
                throw new Error('Token not bound to this resource - re-authenticate required');
            }
        }

        return response.json();
    }

    generateCodeVerifier() {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        return btoa(String.fromCharCode.apply(null, array))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
    }

    async generateCodeChallenge(verifier) {
        const encoder = new TextEncoder();
        const data = encoder.encode(verifier);
        const digest = await crypto.subtle.digest('SHA-256', data);
        return btoa(String.fromCharCode.apply(null, new Uint8Array(digest)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
    }

    generateState() {
        const array = new Uint8Array(16);
        crypto.getRandomValues(array);
        return btoa(String.fromCharCode.apply(null, array));
    }
}
```

## Public/Authless MCP Servers

For public MCP servers serving publicly available data, you can bypass authentication entirely.

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
    // ...
}
```

## Security Considerations

When implementing authentication:

### 1. Token Security
- **Use HTTPS only** for all OAuth endpoints
- **Implement token rotation** with refresh tokens
- **Set appropriate token expiration** (1 hour for access tokens)
- **Store tokens securely** with encryption at rest

### 2. PKCE Requirements
- **Always require PKCE** for OAuth 2.1 compliance
- **Use S256 code challenge method** only
- **Validate code verifier** on token exchange

### 3. Resource Binding (2025-06-18)
- **Validate resource parameter** in authorization requests
- **Bind tokens to specific resources** using RFC 8707
- **Validate audience claims** on token usage
- **Prevent token misuse** across different resources

### 4. Rate Limiting
```php
// Implement rate limiting for auth endpoints
class RateLimiter {
    public static function checkAuthLimit($ip, $endpoint): bool {
        $key = "auth_limit:{$endpoint}:{$ip}";
        $attempts = cache_get($key) ?? 0;

        if ($attempts >= 5) {
            return false; // Rate limited
        }

        cache_set($key, $attempts + 1, 3600); // 1 hour window
        return true;
    }
}

// Use in OAuth endpoints
if (!RateLimiter::checkAuthLimit($ip, 'token')) {
    return $this->errorResponse('rate_limited', 'Too many token requests');
}
```

### 5. Session Security
- **Use cryptographically secure session IDs**
- **Implement session timeout**
- **Clear sensitive session data** after OAuth flow
- **Prevent session fixation** attacks

### 6. Input Validation
- **Validate all OAuth parameters** against specs
- **Sanitize redirect URIs** to prevent open redirects
- **Validate state parameters** to prevent CSRF
- **Check client credentials** securely

This authentication system provides enterprise-grade security with OAuth 2.1 compliance, RFC 8707 Resource Indicators support, multi-tenant isolation, social provider integration, and flexible deployment options for both authenticated and public MCP servers.
