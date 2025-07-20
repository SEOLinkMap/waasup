# WaaSuP with your website

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PSR-15](https://img.shields.io/badge/PSR-15-orange.svg)](https://www.php-fig.org/psr/psr-15/)
[![PSR-7](https://img.shields.io/badge/PSR-7-orange.svg)](https://www.php-fig.org/psr/psr-7/)
[![Composer](https://img.shields.io/badge/composer-ready-brightgreen.svg)](https://packagist.org)
[![Build Status](https://img.shields.io/badge/build-passing-brightgreen.svg)](#)
[![Coverage](https://img.shields.io/badge/coverage-85%25-brightgreen.svg)](#)

**WaaSuP** (Website as a Server using PHP) - A production-ready, SaaS-oriented Model Context Protocol (MCP) server implementation for PHP. Built with enterprise-grade features including OAuth 2.1 authentication, real-time Server-Sent Events (SSE), and comprehensive tool management.

## 🚀 Try It Live

Want to see WaaSuP in action? **Try our live demo at seolinkmap.com/mcp-repo** with your favorite LLM or agentic tool! This demo showcases the server's capabilities with a public Website-as-a-Server (MCP authless).

> **Built by [SEOLinkMap](https://seolinkmap.com)** - The public production implementation powering chat and agentic tools to use with our existing SEO intelligence platform.

## ✨ Features

- 🔐 **OAuth 2.1 Authentication** - Complete OAuth flow with token validation and scope management
- ⚡ **Real-time SSE Transport** - Server-Sent Events for instant message delivery
- 🛠️ **Flexible Tool System** - Easy tool registration with both class-based and callable approaches
- 🏢 **Multi-tenant Architecture** - Agency/user context isolation for SaaS applications
- 📊 **Production Ready** - Comprehensive logging, error handling, and session management
- 🔌 **Framework Agnostic** - PSR-compliant with Slim Framework integration included
- 💾 **Database & Memory Storage** - Multiple storage backends for different deployment scenarios
- 🌐 **CORS Support** - Full cross-origin resource sharing configuration

## Requirements

- PHP 8.1 or higher
- Composer
- Database (MySQL/PostgreSQL recommended for production)

## Installation

```bash
composer require seolinkmap/waasup

# For PSR-3 logging support
composer require monolog/monolog

# For Slim Framework integration
composer require slim/slim slim/psr7
```

## Database Setup

1. Import the database schema:
```bash
mysql -u your_user -p your_database < vendor/seolinkmap/waasup/examples/database/database-schema.sql
```

2. Create your first agency (or configure your existing database):
```sql
INSERT INTO mcp_agencies (uuid, name, active)
VALUES ('550e8400-e29b-41d4-a716-446655440000', 'My Company', 1);
```

3. Create an OAuth token:
```sql
INSERT INTO mcp_oauth_tokens (
    access_token, scope, expires_at, agency_id
) VALUES (
    'your-secret-token-here',
    'mcp:read mcp:write',
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    1
);
```

## Quick Start

### Basic Server Setup

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\{ResponseFactory, StreamFactory};
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

// Database connection
$pdo = new PDO('mysql:host=localhost;dbname=mcp_server', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Initialize components
$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();

// Configuration
$config = [
    'server_info' => [
        'name' => 'My MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'https://your-domain.com'
    ]
];

// Create MCP provider
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config
);

// Setup Slim app
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// OAuth discovery endpoints
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

// Main MCP endpoint
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

$app->run();
```

### Adding Tools

#### Built-in Tools

The server includes several built-in tools that you can register:

```php
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};

// Register built-in tools
$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));
```

- **`ping`** - Test connectivity and response times
- **`server_info`** - Get server information and capabilities

#### Callable Tool (Simple)

```php
$toolRegistry->register('get_weather', function($params, $context) {
    $location = $params['location'] ?? 'Unknown';

    // Your weather API logic here
    return [
        'location' => $location,
        'temperature' => '22°C',
        'condition' => 'Sunny',
        'timestamp' => date('c')
    ];
}, [
    'description' => 'Get weather information for a location',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'location' => [
                'type' => 'string',
                'description' => 'Location to get weather for'
            ]
        ],
        'required' => ['location']
    ]
]);
```

#### Class-based Tool (Advanced)

```php
use Seolinkmap\Waasup\Tools\AbstractTool;

class DatabaseQueryTool extends AbstractTool
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        parent::__construct(
            'db_query',
            'Execute safe database queries',
            [
                'properties' => [
                    'query' => ['type' => 'string'],
                    'params' => ['type' => 'array']
                ],
                'required' => ['query']
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        // Implement your database query logic
        // with proper security checks
        return ['result' => 'Query executed successfully'];
    }
}

// Register the tool
$toolRegistry->registerTool(new DatabaseQueryTool($pdo));
```

### Adding Prompts

```php
$promptRegistry->register('greeting', function($arguments, $context) {
    $name = $arguments['name'] ?? 'there';
    return [
        'description' => 'A friendly greeting prompt',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => "Please greet {$name} in a friendly way."
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

### Adding Resources

```php
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
```

## OAuth 2.1 Flow

### Discovery

1. Client discovers endpoints:
```http
GET /.well-known/oauth-authorization-server
```

2. Client requests authorization:
```http
GET /oauth/authorize?response_type=code&client_id=YOUR_CLIENT&redirect_uri=YOUR_CALLBACK
```

3. Client exchanges code for token:
```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&code=AUTH_CODE&client_id=YOUR_CLIENT
```

## MCP Protocol Usage

### SSE Connection & Requests

```bash
# Establish SSE connection
GET /mcp/550e8400-e29b-41d4-a716-446655440000
Authorization: Bearer your-access-token

# Send MCP requests
POST /mcp/550e8400-e29b-41d4-a716-446655440000
Authorization: Bearer your-access-token
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "method": "initialize",
  "params": {
    "protocolVersion": "2024-11-05",
    "capabilities": {},
    "clientInfo": {
      "name": "My MCP Client",
      "version": "1.0.0"
    }
  },
  "id": 1
}
```

## Configuration

### Server Configuration

```php
$config = [
    'supported_versions' => ['2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Your MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency', 'user'],
        'validate_scope' => true,
        'required_scopes' => ['mcp:read'],
        'base_url' => 'https://your-domain.com'
    ],
    'sse' => [
        'keepalive_interval' => 1,      // seconds
        'max_connection_time' => 1800,  // 30 minutes
        'switch_interval_after' => 60   // switch to longer intervals
    ]
];
```

### Database Storage

```php
$storage = new DatabaseStorage($pdo, [
    'table_prefix' => 'mcp_',
    'cleanup_interval' => 3600 // Clean expired data every hour
]);
```

### Memory Storage (Development/Testing)

```php
use Seolinkmap\Waasup\Storage\MemoryStorage;

$storage = new MemoryStorage();

// Add test data
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1,
    'name' => 'Test Agency',
    'active' => true
]);

$storage->addToken('test-token', [
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 3600,
    'revoked' => false
]);
```

## Logging

### With Monolog

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('/var/log/mcp-server.log', Logger::INFO));

$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $responseFactory,
    $streamFactory,
    $config,
    $logger // Pass logger here
);
```

### Custom Logger

```php
class CustomLogger implements Psr\Log\LoggerInterface
{
    public function info($message, array $context = []): void
    {
        // Your custom logging logic
        file_put_contents('/tmp/mcp.log',
            date('Y-m-d H:i:s') . " [INFO] $message " . json_encode($context) . "\n",
            FILE_APPEND
        );
    }

    // Implement other LoggerInterface methods...
}
```

## Framework Integration

### Laravel

#### Service Provider Registration

Add the service provider to your Laravel application:

```php
// Add to config/app.php providers array
'providers' => [
    // ...
    Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
],
```

#### Creating an MCP Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;

class MCPController extends Controller
{
    public function handleMCP(Request $request, string $agencyUuid, ?string $sessID = null): Response
    {
        // Initialize storage with Laravel's database connection
        $storage = new DatabaseStorage(DB::connection()->getPdo(), ['table_prefix' => 'mcp_']);

        // Initialize registries
        $toolRegistry = new ToolRegistry();
        $promptRegistry = new PromptRegistry();
        $resourceRegistry = new ResourceRegistry();

        // Register your tools
        $toolRegistry->register('get_user_count', function($params, $context) {
            return ['user_count' => \App\Models\User::count()];
        }, [
            'description' => 'Get total user count',
            'inputSchema' => ['type' => 'object']
        ]);

        // Configuration
        $config = [
            'server_info' => [
                'name' => config('app.name') . ' MCP Server',
                'version' => '1.0.0'
            ],
            'auth' => [
                'context_types' => ['agency'],
                'base_url' => config('app.url')
            ]
        ];

        // Create and use the Laravel MCP provider
        $mcpProvider = app(LaravelMCPProvider::class);

        return $mcpProvider->handleMCP($request);
    }

    public function handleAuthDiscovery(Request $request): Response
    {
        $mcpProvider = app(LaravelMCPProvider::class);
        return $mcpProvider->handleAuthDiscovery($request);
    }
}
```

#### Route Registration

```php
// In routes/web.php or routes/api.php
use App\Http\Controllers\MCPController;

// OAuth discovery endpoints
Route::get('/.well-known/oauth-authorization-server', [MCPController::class, 'handleAuthDiscovery']);

// MCP endpoints with authentication middleware
Route::group(['middleware' => ['mcp.auth']], function () {
    Route::match(['GET', 'POST'], '/mcp/{agencyUuid}/{sessID?}', [MCPController::class, 'handleMCP']);
});
```

#### Advanced Laravel Integration

```php
// Create a dedicated MCP service
<?php

namespace App\Services;

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class MCPService
{
    private MCPSaaSServer $server;

    public function __construct()
    {
        $storage = new DatabaseStorage(\DB::connection()->getPdo());
        $toolRegistry = new ToolRegistry();

        // Register Laravel-specific tools
        $this->registerLaravelTools($toolRegistry);

        $config = [
            'server_info' => [
                'name' => config('app.name') . ' MCP API',
                'version' => config('app.version', '1.0.0')
            ],
            'auth' => [
                'base_url' => config('app.url')
            ]
        ];

        $this->server = new MCPSaaSServer($storage, $toolRegistry, /*...*/, $config);
    }

    private function registerLaravelTools(ToolRegistry $registry): void
    {
        // Register tools that use Laravel features
        $registry->register('get_models', function($params, $context) {
            return [
                'users' => \App\Models\User::count(),
                'posts' => \App\Models\Post::count(),
            ];
        });

        $registry->register('send_notification', function($params, $context) {
            // Use Laravel's notification system
            \Notification::send($user, new \App\Notifications\MCPNotification($params));
            return ['status' => 'sent'];
        });
    }

    public function getServer(): MCPSaaSServer
    {
        return $this->server;
    }
}
```

### Standalone (PSR-7)

```php
use Seolinkmap\Waasup\MCPSaaSServer;

$server = new MCPSaaSServer($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $config, $logger);

// Handle PSR-7 request
$response = $server->handle($request, $response);
```

## Advanced Usage

### Custom Authentication

```php
class CustomAuthMiddleware extends AuthMiddleware
{
    protected function extractContextId(Request $request): ?string
    {
        // Custom logic to extract context from request
        $customHeader = $request->getHeaderLine('X-Custom-Context');
        return $customHeader ?: parent::extractContextId($request);
    }
}
```

### Tool Annotations

```php
$toolRegistry->register('dangerous_operation', $handler, [
    'description' => 'Performs a dangerous operation',
    'annotations' => [
        'readOnlyHint' => false,
        'destructiveHint' => true,
        'idempotentHint' => false
    ]
]);
```

### Session Management

```php
// Custom session handling
$server->addTool('get_session', function($params, $context) {
    $sessionData = $context['session_data'] ?? [];
    return ['session' => $sessionData];
});
```

## Built-in Tools

The server includes several built-in tools for testing and basic functionality that you can register:

### Ping Tool
```php
use Seolinkmap\Waasup\Tools\Built\PingTool;

// Register the ping tool
$toolRegistry->registerTool(new PingTool());
// Tests connectivity and returns server timestamp
```

### Server Info Tool
```php
use Seolinkmap\Waasup\Tools\Built\ServerInfoTool;

// Register the server info tool
$toolRegistry->registerTool(new ServerInfoTool($config));
// Returns server configuration and capabilities
```

## API Reference

### MCP Methods

| Method | Description |
|--------|-------------|
| `tools/list` | List all available tools |
| `tools/call` | Execute a specific tool |
| `prompts/list` | List all available prompts |
| `prompts/get` | Get a specific prompt |
| `resources/list` | List all available resources |
| `resources/read` | Read a specific resource |
| `resources/templates/list` | List resource templates |
| `initialize` | Initialize MCP session |
| `ping` | Health check endpoint |

### Error Codes

| Code | Description |
|------|-------------|
| `-32000` | Authentication required |
| `-32001` | Session required |
| `-32600` | Invalid Request |
| `-32601` | Method not found |
| `-32602` | Invalid params |
| `-32603` | Internal error |
| `-32700` | Parse error |

## Testing

```bash
# Run tests
composer test

# Static analysis
composer analyse

# Code formatting
composer format
```

### Example Test

```php
use PHPUnit\Framework\TestCase;
use Seolinkmap\Waasup\Storage\MemoryStorage;

class MCPServerTest extends TestCase
{
    public function testToolExecution()
    {
        $storage = new MemoryStorage();
        $toolRegistry = new ToolRegistry();

        $toolRegistry->register('test_tool', function($params) {
            return ['result' => 'success'];
        });

        $result = $toolRegistry->execute('test_tool', []);
        $this->assertEquals(['result' => 'success'], $result);
    }
}
```

## Security

- **Token Validation**: All requests require valid OAuth tokens
- **Scope Checking**: Configurable scope validation for fine-grained access
- **SQL Injection**: All database queries use prepared statements
- **Session Security**: Cryptographically secure session ID generation
- **CORS**: Configurable CORS policies for cross-origin requests

## Deployment

### Docker

```dockerfile
FROM php:8.1-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html
WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

EXPOSE 9000
CMD ["php-fpm"]
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-mcp-server.com;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # SSE connection timeout
    location /mcp/ {
        proxy_read_timeout 1800s;
        proxy_send_timeout 1800s;
    }
}
```

## Contributing

We welcome contributions! This server is actively used in production at **[SEOLinkMap](https://seolinkmap.com)** where it powers our SEO intelligence platform.

- Fork the repository
- Create a feature branch (`git checkout -b feature/amazing-feature`)
- Make your changes
- Add tests for new functionality
- Ensure all tests pass (`composer test`)
- Commit your changes (`git commit -m 'Add amazing feature'`)
- Push to the branch (`git push origin feature/amazing-feature`)
- Open a Pull Request

## Development

```bash
git clone https://github.com/seolinkmap/waasup.git
cd waasup
composer install
cp .env.example .env
# Configure your .env file
php -S localhost:8000 -t examples/slim-framework/
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- [Anthropic](https://anthropic.com) for the Model Context Protocol specification
- [PHP-FIG](https://www.php-fig.org/) for the PSR standards
- [Slim Framework](https://slimframework.com/) for the excellent HTTP foundation

## Support & Community

- 🌐 **Live Demo**: https://seolinkmap.com/mcp-repo (use WaaSuP server to chat with WaaSuP repository... help, install, tool building)
- 📖 **Documentation**: [GitHub Wiki](https://github.com/seolinkmap/waasup/wiki)
- 🐛 **Issues**: [GitHub Issues](https://github.com/seolinkmap/waasup/issues)
- 💬 **Discussions**: [GitHub Discussions](https://github.com/seolinkmap/waasup/discussions)

Built with ❤️ by **[SEOLinkMap](https://seolinkmap.com)** for the MCP community.
