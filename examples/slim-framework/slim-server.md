# Slim Framework MCP Server Integration Guide

This guide shows how to integrate the WaaSuP MCP SaaS Server with Slim Framework 4 using the library

## Setup and Dependencies

```json
{
    "require": {
        "slim/slim": "^4.0",
        "slim/psr7": "^1.0",
        "seolinkmap/waasup": "^2.0",
        "monolog/monolog": "^3.0"
    }
}
```

## Database Setup

```sql
-- Use the library's default table structure
CREATE TABLE mcp_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    session_data TEXT,
    expires_at DATETIME,
    created_at DATETIME
);

CREATE TABLE mcp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255),
    message_data TEXT,
    context_data TEXT,
    created_at DATETIME
);

CREATE TABLE mcp_oauth_clients (
    client_id VARCHAR(255) PRIMARY KEY,
    client_secret VARCHAR(255),
    client_name VARCHAR(255),
    redirect_uris TEXT,
    grant_types TEXT,
    response_types TEXT,
    created_at DATETIME
);

CREATE TABLE mcp_oauth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(255),
    access_token VARCHAR(255),
    refresh_token VARCHAR(255),
    token_type VARCHAR(50) DEFAULT 'Bearer',
    scope TEXT,
    expires_at DATETIME,
    agency_id INT,
    user_id INT,
    resource VARCHAR(255),
    aud TEXT,
    revoked TINYINT DEFAULT 0,
    code_challenge VARCHAR(255),
    code_challenge_method VARCHAR(10),
    created_at DATETIME
);

-- Your existing application tables (or use the library tables)
CREATE TABLE agencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE,
    name VARCHAR(255),
    active TINYINT DEFAULT 1
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_id INT,
    name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255)
);
```

## Complete Working Example

### `public/index.php`

```php
<?php

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';

// Create Slim app
$app = AppFactory::create();

// Container setup
$container = $app->getContainer();

// PDO setup
$pdo = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');

// Register some example tools
function registerExampleTools($toolRegistry, $promptRegistry, $resourceRegistry) {
    $toolRegistry->register(
        'get_time',
        function ($params, $context) {
            return [
                'current_time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
                'user_id' => $context['token_data']['user_id'] ?? null
            ];
        },
        [
            'description' => 'Get current server time',
            'inputSchema' => ['type' => 'object']
        ]
    );

    $toolRegistry->register(
        'echo_message',
        function ($params, $context) {
            return [
                'message' => $params['message'] ?? 'Hello World',
                'echoed_at' => date('c')
            ];
        },
        [
            'description' => 'Echo a message back',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'description' => 'Message to echo']
                ]
            ]
        ]
    );
}

// Public MCP endpoint (no auth)
$app->map(
    ['GET', 'POST', 'OPTIONS'],
    '/mcp-public[/{sessID}]',
    function (Request $request, Response $response, array $args) use ($pdo) {
        if ($request->getMethod() === 'OPTIONS') {
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Mcp-Session-Id')
                ->withStatus(200);
        }

        $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
            'database' => ['table_prefix' => 'mcp_']
        ]);

        $toolRegistry = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();
        $promptRegistry = new \Seolinkmap\Waasup\Prompts\Registry\PromptRegistry();
        $resourceRegistry = new \Seolinkmap\Waasup\Resources\Registry\ResourceRegistry();

        registerExampleTools($toolRegistry, $promptRegistry, $resourceRegistry);

        $config = [
            'base_url' => 'https://yourdomain.com/mcp-public',
            'server_info' => [
                'name' => 'Example MCP Server',
                'version' => '1.0.0'
            ],
            'auth' => ['authless' => true]
        ];

        $logger = new \Monolog\Logger('mcp');
        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

        $mcpProvider = new \Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider(
            $storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $responseFactory,
            $streamFactory,
            $config,
            $logger
        );

        return $mcpProvider->handleMCP($request, $response);
    }
);

// OAuth endpoints
$app->group('/oauth', function (RouteCollectorProxy $group) use ($pdo) {
    $group->get('/authorize', function (Request $request, Response $response) use ($pdo) {
        $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
            'database' => ['table_prefix' => 'mcp_']
        ]);

        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage,
            $responseFactory,
            $streamFactory,
            ['base_url' => 'https://yourdomain.com']
        );

        return $oauthServer->authorize($request, $response);
    });

    $group->post('/token', function (Request $request, Response $response) use ($pdo) {
        $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
            'database' => ['table_prefix' => 'mcp_']
        ]);

        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage,
            $responseFactory,
            $streamFactory,
            ['base_url' => 'https://yourdomain.com']
        );

        return $oauthServer->token($request, $response);
    });

    $group->post('/register', function (Request $request, Response $response) use ($pdo) {
        $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
            'database' => ['table_prefix' => 'mcp_']
        ]);

        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

        $oauthServer = new \Seolinkmap\Waasup\Auth\OAuthServer(
            $storage,
            $responseFactory,
            $streamFactory,
            []
        );

        return $oauthServer->register($request, $response);
    });
});

// Private MCP endpoint (with OAuth auth)
$app->map(
    ['GET', 'POST', 'OPTIONS'],
    '/mcp-private/{agencyUuid}[/{sessID}]',
    function (Request $request, Response $response, array $args) use ($pdo) {
        if ($request->getMethod() === 'OPTIONS') {
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id')
                ->withStatus(200);
        }

        $agencyUuid = $args['agencyUuid'];
        $sessID = $args['sessID'] ?? null;

        $baseUrl = "https://yourdomain.com/mcp-private/{$agencyUuid}";
        if ($sessID) {
            $baseUrl .= "/{$sessID}";
        }

        $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
            'base_url' => $baseUrl,
            'database' => ['table_prefix' => 'mcp_']
        ]);

        $toolRegistry = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();
        $promptRegistry = new \Seolinkmap\Waasup\Prompts\Registry\PromptRegistry();
        $resourceRegistry = new \Seolinkmap\Waasup\Resources\Registry\ResourceRegistry();

        registerExampleTools($toolRegistry, $promptRegistry, $resourceRegistry);

        $config = [
            'base_url' => $baseUrl,
            'server_info' => [
                'name' => 'Private MCP Server',
                'version' => '1.0.0'
            ],
            'auth' => [
                'context_types' => ['agency'],
                'required_scopes' => ['mcp:read']
            ],
            'oauth' => [
                'base_url' => 'https://yourdomain.com'
            ]
        ];

        $logger = new \Monolog\Logger('mcp');
        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

        $mcpProvider = new \Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider(
            $storage,
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $responseFactory,
            $streamFactory,
            $config,
            $logger
        );

        return $mcpProvider->handleMCP($request, $response);
    }
)->add(function (Request $request, \Psr\Http\Server\RequestHandlerInterface $handler) use ($pdo) {
    // Auth middleware
    $agencyUuid = $request->getAttribute('__route__')->getArgument('agencyUuid');
    $baseUrl = "https://yourdomain.com/mcp-private/{$agencyUuid}";

    $storage = new \Seolinkmap\Waasup\Storage\DatabaseStorage($pdo, [
        'base_url' => $baseUrl,
        'database' => ['table_prefix' => 'mcp_']
    ]);

    $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
    $streamFactory = new \Slim\Psr7\Factory\StreamFactory();

    $config = [
        'base_url' => $baseUrl,
        'auth' => ['context_types' => ['agency']]
    ];

    $authMiddleware = new \Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware(
        $storage,
        $responseFactory,
        $streamFactory,
        $config
    );

    return $authMiddleware($request, $handler);
});

// Discovery endpoints
$app->get('/.well-known/oauth-authorization-server[/{path:.*}]', function (Request $request, Response $response) {
    $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider([
        'oauth' => ['base_url' => 'https://yourdomain.com']
    ]);

    return $discoveryProvider->authorizationServer($request, $response);
});

$app->get('/.well-known/oauth-protected-resource[/{path:.*}]', function (Request $request, Response $response) {
    $discoveryProvider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider([
        'base_url' => 'https://yourdomain.com'
    ]);

    return $discoveryProvider->protectedResource($request, $response);
});

$app->run();
```

## Testing

```bash
# Test public endpoint
curl -X POST https://yourdomain.com/mcp-public \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}'

# Register OAuth client
curl -X POST https://yourdomain.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{"client_name":"Test Client","redirect_uris":["http://localhost/callback"]}'

# Test private endpoint (after getting OAuth token)
curl -X POST https://yourdomain.com/mcp-private/your-agency-uuid \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

That's it. The library does everything - just configure it and use it directly.
