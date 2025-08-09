# Simple MCP Server Example

This example demonstrates how to create a Model Context Protocol (MCP) server using the WaaSuP library with Slim Framework 4. The library handles all the MCP protocol complexity - you just register your tools and configure routes.

## Prerequisites

- PHP 8.1+
- Composer
- MySQL/MariaDB database

## Installation

1. **Create a new project**:
```bash
mkdir my-mcp-server
cd my-mcp-server
composer init
```

2. **Install dependencies**:
```bash
composer require seolinkmap/waasup
composer require slim/slim:"4.*"
composer require slim/psr7
composer require monolog/monolog
```

3. **Project structure**:
```
my-mcp-server/
├── public/
│   └── index.php
├── database/
│   └── schema.sql
└── composer.json
```

## Database Setup

```sql
-- Required tables (use the library's defaults)
CREATE TABLE `mcp_agencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`)
);

CREATE TABLE `mcp_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `agency_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uuid` (`uuid`)
);

CREATE TABLE `mcp_oauth_clients` (
  `client_id` varchar(255) NOT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `redirect_uris` text NOT NULL,
  `grant_types` text DEFAULT NULL,
  `response_types` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`)
);

CREATE TABLE `mcp_oauth_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `access_token` varchar(255) NOT NULL,
  `refresh_token` varchar(255) DEFAULT NULL,
  `token_type` varchar(50) DEFAULT 'Bearer',
  `scope` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `agency_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `resource` varchar(255) DEFAULT NULL,
  `aud` text DEFAULT NULL,
  `revoked` tinyint(1) DEFAULT 0,
  `code_challenge` varchar(255) DEFAULT NULL,
  `code_challenge_method` varchar(10) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_token` (`access_token`)
);

CREATE TABLE `mcp_sessions` (
  `session_id` varchar(255) NOT NULL,
  `session_data` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`)
);

CREATE TABLE `mcp_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `message_data` text NOT NULL,
  `context_data` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`)
);

CREATE TABLE `mcp_sampling_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `request_id` varchar(255) NOT NULL,
  `response_data` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

CREATE TABLE `mcp_roots_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `request_id` varchar(255) NOT NULL,
  `response_data` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

CREATE TABLE `mcp_elicitation_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(255) NOT NULL,
  `request_id` varchar(255) NOT NULL,
  `response_data` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

-- Sample data
INSERT INTO `mcp_agencies` (`uuid`, `name`, `active`) VALUES
('550e8400-e29b-41d4-a716-446655440000', 'Example Agency', 1);

INSERT INTO `mcp_users` (`uuid`, `agency_id`, `name`, `email`, `password`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 1, 'Demo User', 'demo@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
```

## Complete Application

**public/index.php**:
```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Seolinkmap\Waasup\Auth\OAuthServer;
use Seolinkmap\Waasup\Discovery\WellKnownProvider;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Database setup
$pdo = new PDO(
    'mysql:host=localhost;dbname=your_database;charset=utf8mb4',
    'your_username',
    'your_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Create Slim app
$app = AppFactory::create();
$app->addErrorMiddleware(true, false, true);

// Logger
$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));

// Storage with default table names
$storage = new DatabaseStorage($pdo, [
    'database' => ['table_prefix' => 'mcp_']
]);

// Create registries and register your tools
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

registerMyTools($toolRegistry);
registerMyPrompts($promptRegistry);
registerMyResources($resourceRegistry);

// =============================================================================
// PUBLIC MCP ENDPOINT (No authentication required)
// =============================================================================

$app->map(['GET', 'POST', 'OPTIONS'], '/mcp[/{sessID}]', function ($request, $response, $args) use ($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $logger) {

    $config = [
        'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
        'server_info' => [
            'name' => 'Example MCP Server',
            'version' => '1.0.0'
        ],
        'base_url' => 'https://your-domain.com/mcp',
        'auth' => [
            'authless' => true  // No authentication required
        ]
    ];

    $provider = new SlimMCPProvider(
        $storage,
        $toolRegistry,
        $promptRegistry,
        $resourceRegistry,
        new ResponseFactory(),
        new StreamFactory(),
        $config,
        $logger
    );

    return $provider->handleMCP($request, $response);
});

// =============================================================================
// PRIVATE MCP ENDPOINT (OAuth authentication required)
// =============================================================================

$app->map(['GET', 'POST', 'OPTIONS'], '/mcp-private/{agencyUuid}[/{sessID}]', function ($request, $response, $args) use ($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $logger) {

    $agencyUuid = $args['agencyUuid'];
    $sessID = $args['sessID'] ?? '';

    $baseUrl = "https://your-domain.com/mcp-private/{$agencyUuid}";
    if ($sessID) {
        $baseUrl .= "/{$sessID}";
    }

    $config = [
        'base_url' => $baseUrl,
        'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
        'server_info' => [
            'name' => 'Example Private MCP Server',
            'version' => '1.0.0'
        ],
        'auth' => [
            'context_types' => ['agency'],
            'authless' => false
        ],
        'oauth' => [
            'base_url' => 'https://your-domain.com/oauth'
        ],
        'database' => [
            'table_prefix' => 'mcp_'
        ]
    ];

    $provider = new SlimMCPProvider(
        $storage,
        $toolRegistry,
        $promptRegistry,
        $resourceRegistry,
        new ResponseFactory(),
        new StreamFactory(),
        $config,
        $logger
    );

    return $provider->handleMCP($request, $response);

})->add(function ($request, $handler) use ($storage) {
    // The library's AuthMiddleware handles all the OAuth complexity
    $authMiddleware = new \Seolinkmap\Waasup\Auth\Middleware\AuthMiddleware(
        $storage,
        new ResponseFactory(),
        new StreamFactory(),
        [
            'base_url' => 'https://your-domain.com/mcp-private',
            'auth' => [
                'context_types' => ['agency'],
                'authless' => false
            ],
            'oauth' => [
                'base_url' => 'https://your-domain.com/oauth'
            ],
            'database' => [
                'table_prefix' => 'mcp_'
            ]
        ]
    );

    return $authMiddleware($request, $handler);
});

// =============================================================================
// OAUTH ENDPOINTS (Use the library's OAuthServer)
// =============================================================================

$app->group('/oauth', function ($group) use ($storage) {

    $config = [
        'base_url' => 'https://your-domain.com/mcp-private',
        'database' => [
            'table_prefix' => 'mcp_'
        ],
        'oauth' => [
            'auth_server' => [
                'providers' => [
                    'google' => [
                        'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? null,
                        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? null,
                        'redirect_uri' => 'https://your-domain.com/oauth/google/callback'
                    ]
                ]
            ]
        ]
    ];

    $oauthServer = new OAuthServer(
        $storage,
        new ResponseFactory(),
        new StreamFactory(),
        $config
    );

    $group->get('/authorize', function ($request, $response) use ($oauthServer) {
        return $oauthServer->authorize($request, $response);
    });

    $group->post('/verify', function ($request, $response) use ($oauthServer) {
        return $oauthServer->verify($request, $response);
    });

    $group->post('/consent', function ($request, $response) use ($oauthServer) {
        return $oauthServer->consent($request, $response);
    });

    $group->post('/token', function ($request, $response) use ($oauthServer) {
        return $oauthServer->token($request, $response);
    });

    $group->post('/revoke', function ($request, $response) use ($oauthServer) {
        return $oauthServer->revoke($request, $response);
    });

    $group->post('/register', function ($request, $response) use ($oauthServer) {
        return $oauthServer->register($request, $response);
    });
});

// =============================================================================
// DISCOVERY ENDPOINTS (Use the library's WellKnownProvider)
// =============================================================================

$wellKnownProvider = new WellKnownProvider([
    'base_url' => 'https://your-domain.com',
    'oauth' => [
        'base_url' => 'https://your-domain.com/oauth'
    ]
]);

$app->get('/.well-known/oauth-authorization-server[/{path:.*}]', function ($request, $response) use ($wellKnownProvider) {
    return $wellKnownProvider->authorizationServer($request, $response);
});

$app->get('/.well-known/oauth-protected-resource[/{path:.*}]', function ($request, $response) use ($wellKnownProvider) {
    return $wellKnownProvider->protectedResource($request, $response);
});

$app->run();

// =============================================================================
// YOUR CUSTOM TOOLS, PROMPTS & RESOURCES
// =============================================================================

function registerMyTools(ToolRegistry $registry): void
{
    // Simple echo tool
    $registry->register(
        'echo',
        function ($params, $context) {
            return [
                'message' => $params['message'] ?? 'Hello World!',
                'timestamp' => date('c'),
                'has_context' => !empty($context)
            ];
        },
        [
            'description' => 'Echo back a message with timestamp',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Message to echo back'
                    ]
                ]
            ]
        ]
    );

    // Calculator tool
    $registry->register(
        'calculate',
        function ($params, $context) {
            $operation = $params['operation'] ?? 'add';
            $a = floatval($params['a'] ?? 0);
            $b = floatval($params['b'] ?? 0);

            $result = match($operation) {
                'add' => $a + $b,
                'subtract' => $a - $b,
                'multiply' => $a * $b,
                'divide' => $b != 0 ? $a / $b : 'Division by zero',
                default => 'Unknown operation'
            };

            return [
                'operation' => $operation,
                'operands' => ['a' => $a, 'b' => $b],
                'result' => $result
            ];
        },
        [
            'description' => 'Perform basic mathematical operations',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['add', 'subtract', 'multiply', 'divide']
                    ],
                    'a' => ['type' => 'number'],
                    'b' => ['type' => 'number']
                ],
                'required' => ['operation', 'a', 'b']
            ]
        ]
    );
}

function registerMyPrompts(PromptRegistry $registry): void
{
    $registry->register(
        'greeting',
        function ($arguments, $context) {
            $name = $arguments['name'] ?? 'there';
            $tone = $arguments['tone'] ?? 'friendly';

            $greeting = match($tone) {
                'formal' => "Good day, {$name}. I hope this message finds you well.",
                'casual' => "Hey {$name}! How's it going?",
                'professional' => "Hello {$name}, I trust you are having a productive day.",
                default => "Hi {$name}! Nice to meet you."
            };

            return [
                'description' => 'A personalized greeting message',
                'messages' => [
                    [
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'text', 'text' => $greeting]
                        ]
                    ]
                ]
            ];
        },
        [
            'description' => 'Generate a personalized greeting',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'tone' => [
                        'type' => 'string',
                        'enum' => ['friendly', 'formal', 'casual', 'professional']
                    ]
                ]
            ]
        ]
    );
}

function registerMyResources(ResourceRegistry $registry): void
{
    $registry->register(
        'server://status',
        function ($uri, $context) {
            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode([
                            'status' => 'healthy',
                            'load_avg' => sys_getloadavg(),
                            'timestamp' => date('c'),
                            'version' => '1.0.0'
                        ], JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        },
        [
            'name' => 'Server Status',
            'description' => 'Current server health information',
            'mimeType' => 'application/json'
        ]
    );
}
```

## That's It!

The library handles everything:

- **MCP protocol compliance** - All versions supported automatically
- **OAuth authentication** - Complete flow with discovery endpoints
- **Session management** - Automatic session handling and cleanup
- **Message queuing** - SSE/streaming for real-time communication
- **Error handling** - Proper MCP error responses
- **Security** - Token validation, CORS, etc.

## Usage Examples

### Test the Public Endpoint

```bash
# Initialize
curl -X POST https://your-domain.com/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "params": {"protocolVersion": "2024-11-05", "capabilities": {}},
    "id": 1
  }'

# List tools (use session ID from initialize response)
curl -X POST https://your-domain.com/mcp/your-session-id \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: your-session-id" \
  -d '{"jsonrpc": "2.0", "method": "tools/list", "id": 2}'

# Call echo tool
curl -X POST https://your-domain.com/mcp/your-session-id \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: your-session-id" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {"name": "echo", "arguments": {"message": "Hello MCP!"}},
    "id": 3
  }'
```

### Test OAuth Registration

```bash
# Register OAuth client
curl -X POST https://your-domain.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{
    "client_name": "My MCP Client",
    "redirect_uris": ["https://my-app.com/callback"]
  }'
```

## Configuration

Just change the config arrays to customize behavior:

```php
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => ['name' => 'My Server', 'version' => '1.0.0'],
    'base_url' => 'https://your-domain.com/mcp',
    'auth' => [
        'authless' => true,                    // Enable public access
        'context_types' => ['agency', 'user'], // Valid context types
        'required_scopes' => ['mcp:read']       // Required OAuth scopes
    ],
    'oauth' => [
        'base_url' => 'https://your-domain.com/oauth',
        'auth_server' => [
            'providers' => [
                'google' => [
                    'client_id' => 'your-google-client-id',
                    'client_secret' => 'your-google-client-secret',
                    'redirect_uri' => 'https://your-domain.com/oauth/google/callback'
                ]
            ]
        ]
    ],
    'database' => [
        'table_prefix' => 'mcp_'
    ]
];
```

## Focus on Your Business Logic

The library handles all the MCP complexity. You just:

1. **Register your tools** - Functions that do actual work
2. **Register prompts** - Template messages for LLMs
3. **Register resources** - Data/files the LLM can access
4. **Configure routes** - Set up your endpoints

The library does the rest!
