# WaaSuP with your website

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PSR-15](https://img.shields.io/badge/PSR-15-orange.svg)](https://www.php-fig.org/psr/psr-15/)
[![PSR-7](https://img.shields.io/badge/PSR-7-orange.svg)](https://www.php-fig.org/psr/psr-7/)
[![Composer](https://img.shields.io/badge/composer-ready-brightgreen.svg)](https://packagist.org)

**WaaSuP** (Website as a Server using PHP) - A production-ready, SaaS-oriented Model Context Protocol (MCP) server implementation for PHP. Built with enterprise-grade features including OAuth 2.1 authentication, real-time Server-Sent Events (SSE), and comprehensive tool management.

## 🚀 Try It Live

Want to see WaaSuP in action? **Connect to our live demo MCP server** with your favorite LLM or agentic tool!

## 💬 Chat With This Repository
**Get instant help with:**
- ✅ **Installation guidance** - Step-by-step setup for your environment
- ⚙️ **Configuration assistance** - Database setup, OAuth, framework integration
- 🛠️ **Custom tool development** - Build tools specific to your use case
- 🐛 **Troubleshooting** - Debug issues with access to the full codebase
- 📚 **Code exploration** - Understand how features work under the hood

The MCP server has access to our entire repository, documentation, and examples. Ask it anything!

### 📡 Live MCP Server

`https://seolinkmap.com/mcp-repo`

This public MCP endpoint showcases the server's capabilities with a complete Website-as-a-Server implementation (authless).

### 🔗 Need Help Connecting?
**New to MCP servers?** Learn how to connect: **[How to Connect to MCP Servers](https://seolinkmap.com/documentation/how-to-connect-to-mcp-servers)**

Once connected, you can explore our entire repository through chat and get real-time help with WaaSuP installation and configuration.

> **Built by [SEOLinkMap](https://seolinkmap.com)** - This is our production "web server for chat and agentics" powering AI access to our entire SEO intelligence platform.

## ✨ Features

- 🔐 **OAuth 2.1 Authentication** - Complete OAuth flow with RFC 8707 Resource Indicators support for MCP 2025-06-18
- ⚡ **Multi-Transport Support** - Server-Sent Events (SSE) and Streamable HTTP for real-time message delivery
- 🛠️ **Flexible Tool System** - Easy tool registration with both class-based and callable approaches
- 🏢 **Multi-tenant Architecture** - Agency/user context isolation for SaaS applications
- 📊 **Production Ready** - Comprehensive logging, error handling, and session management
- 🔌 **Framework Agnostic** - PSR-compliant with Slim Framework and Laravel integration included
- 💾 **Database & Memory Storage** - Multiple storage backends for different deployment scenarios
- 🌐 **CORS Support** - Full cross-origin resource sharing configuration
- 🎵 **Audio Content Support** - Handle audio content in tools and prompts (MCP 2025-03-26+)
- 📝 **Structured User Input** - Elicitation support for collecting structured data (MCP 2025-06-18)
- 🔄 **Progress Notifications** - Real-time progress updates with version-aware messaging
- 🏷️ **Tool Annotations** - Rich tool metadata for better LLM understanding (MCP 2025-03-26+)
- 📦 **JSON-RPC Batching** - Efficient batch request processing (MCP 2025-03-26)

## Requirements

- PHP 8.1 or higher
- Composer
- Database (MySQL/PostgreSQL recommended for production)

## MCP Protocol Compliance

WaaSuP implements the complete MCP specification across multiple protocol versions with automatic feature gating:

### **Feature Matrix Summary**

| Feature | 2024-11-05 | 2025-03-26 | 2025-06-18 |
|---------|------------|------------|------------|
| Tools | ✅ | ✅ | ✅ |
| Prompts | ✅ | ✅ | ✅ |
| Resources | ✅ | ✅ | ✅ |
| Sampling | ✅ | ✅ | ✅ |
| Roots | ✅ | ✅ | ✅ |
| Ping | ✅ | ✅ | ✅ |
| Progress Notifications | ✅ | ✅ | ✅ |
| Tool Annotations | ❌ | ✅ | ✅ |
| Audio Content | ❌ | ✅ | ✅ |
| Completions | ❌ | ✅ | ✅ |
| JSON-RPC Batching | ❌ | ✅ | ❌ |
| OAuth 2.1 | ❌ | ❌ | ✅ |
| Elicitation | ❌ | ❌ | ✅ |
| Structured Outputs | ❌ | ❌ | ✅ |
| Resource Links | ❌ | ❌ | ✅ |
| Resource Indicators (RFC 8707) | ❌ | ❌ | ✅ (Required) |

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
1. Open `examples/database/database-schema.sql`
2. Use either MySQL section OR PostgreSQL section (not both)
3. Customize table names/prefixes as needed
4. Create only the tables you need (if using your own table mapping)
```

2. Create your first agency:
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
        'version' => '0.0.7'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'https://your-domain.com'
    ]
];

// Create MCP provider
$mcpProvider = new SlimMCPProvider(
    $storage, $toolRegistry, $promptRegistry, $resourceRegistry,
    $responseFactory, $streamFactory, $config
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

#### Simple Callable Tool
```php
$toolRegistry->register('get_weather', function($params, $context) {
    $location = $params['location'] ?? 'Unknown';
    return [
        'location' => $location,
        'temperature' => '22°C',
        'condition' => 'Sunny'
    ];
}, [
    'description' => 'Get weather information for a location',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'Location name']
        ],
        'required' => ['location']
    ]
]);
```

#### Built-in Tools
```php
use Seolinkmap\Waasup\Tools\Built\{PingTool, ServerInfoTool};

$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));
```

### Adding Prompts and Resources

```php
// Register a prompt
$promptRegistry->register('greeting', function($arguments, $context) {
    $name = $arguments['name'] ?? 'there';
    return [
        'description' => 'A friendly greeting prompt',
        'messages' => [[
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => "Please greet {$name}."]]
        ]]
    ];
});

// Register a resource
$resourceRegistry->register('server://status', function($uri, $context) {
    return [
        'contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode(['status' => 'healthy', 'timestamp' => date('c')])
        ]]
    ];
});
```

## Framework Integration

### Laravel Integration

Add the service provider to your Laravel application:

```php
// config/app.php
'providers' => [
    Seolinkmap\Waasup\Integration\Laravel\LaravelServiceProvider::class,
],
```

Register routes and use the provided controller pattern. See the full Laravel integration example in the `/examples` directory.

### Standalone (PSR-7)

```php
use Seolinkmap\Waasup\MCPSaaSServer;

$server = new MCPSaaSServer($storage, $toolRegistry, $promptRegistry, $resourceRegistry, $config, $logger);
$response = $server->handle($request, $response);
```

## Configuration

### Server Configuration

```php
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'Your MCP Server',
        'version' => '0.0.7'
    ],
    'auth' => [
        'context_types' => ['agency', 'user'],
        'validate_scope' => true,
        'required_scopes' => ['mcp:read'],
        'base_url' => 'https://your-domain.com'
    ],
    'sse' => [
        'keepalive_interval' => 1,
        'max_connection_time' => 1800,
        'switch_interval_after' => 60
    ]
];
```

### Storage Options

**Database Storage (Production)**
```php
$storage = new DatabaseStorage($pdo, [
    'table_prefix' => 'mcp_',
    'cleanup_interval' => 3600
]);
```

**Memory Storage (Development/Testing)**
```php
$storage = new MemoryStorage();
// Add test data
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1, 'name' => 'Test Agency', 'active' => true
]);
```

## OAuth 2.1 & Authentication

WaaSuP implements complete OAuth 2.1 with RFC 8707 Resource Indicators for MCP 2025-06-18:

- **Authorization Code Flow** with PKCE (required)
- **Resource Indicators** for token binding to specific MCP endpoints
- **Social Authentication** via Google, LinkedIn, and GitHub
- **Token Validation** with audience claims and scope checking
- **Discovery Endpoints** for client configuration

Social authentication can be configured for each provider:

```php
$config['google'] = [
    'client_id' => 'your-google-client-id',
    'client_secret' => 'your-google-client-secret',
    'redirect_uri' => 'https://your-domain.com/oauth/google/callback'
];
```

## Advanced Features

### Audio Content Handling (MCP 2025-03-26+)

```php
use Seolinkmap\Waasup\Content\AudioContentHandler;

// In your tool
return [
    'content' => [
        ['type' => 'text', 'text' => 'Here is the audio file:'],
        AudioContentHandler::createFromFile('/path/to/audio.mp3', 'example.mp3')
    ]
];
```

### Structured User Input (MCP 2025-06-18)

```php
// Request structured input from user
$requestId = $server->requestElicitation(
    $sessionId,
    'Please provide your contact information',
    [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email']
        ]
    ]
);
```

### Progress Notifications

```php
// Send progress updates during long-running operations
$server->sendProgressNotification($sessionId, 50, 'Processing data...');
```

## API Reference

### MCP Methods

| Method | Description | Supported Versions |
|--------|-------------|-------------------|
| `initialize` | Initialize MCP session | All |
| `ping` | Health check | All |
| `tools/list` | List available tools | All |
| `tools/call` | Execute a tool | All |
| `prompts/list` | List available prompts | All |
| `prompts/get` | Get a prompt | All |
| `resources/list` | List available resources | All |
| `resources/read` | Read a resource | All |
| `completion/complete` | Get completions for arguments | All |
| `sampling/createMessage` | Request LLM sampling | All |
| `roots/list` | List available root directories | All |
| `elicitation/create` | Request user input | 2025-06-18 |


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

## Security

- **Token Validation**: All requests require valid OAuth tokens with proper audience validation
- **Scope Checking**: Configurable scope validation for fine-grained access control
- **SQL Injection Protection**: All database queries use prepared statements
- **Session Security**: Cryptographically secure session ID generation
- **CORS Configuration**: Configurable CORS policies for cross-origin requests
- **DNS Rebinding Protection**: Origin header validation for localhost endpoints

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
- [modelcontextprotocol.io](https://modelcontextprotocol.io/) for official MCP documentation and schema
- [PHP-FIG](https://www.php-fig.org/) for the PSR standards
- [Slim Framework](https://slimframework.com/) for the excellent HTTP foundation

## Support & Community

- 🌐 **Live Demo**: https://seolinkmap.com/mcp-repo (use WaaSuP server to chat with WaaSuP repository... help, install, tool building)
- 📖 **Documentation**: [GitHub Wiki](https://github.com/seolinkmap/waasup/wiki)
- 🐛 **Issues**: [GitHub Issues](https://github.com/seolinkmap/waasup/issues)
- 💬 **Discussions**: [GitHub Discussions](https://github.com/seolinkmap/waasup/discussions)

Built with ❤️ by **[SEOLinkMap](https://seolinkmap.com)** for the MCP community.
