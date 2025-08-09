# Getting Started with WaaSuP

[Installation](getting-started.md) | [Configuration](configuration.md) | [Authentication](authentication.md) | [Building Tools](tools.md) | [API Reference](api-reference.md)

---

## What is WaaSuP?

WaaSuP (Website as a Server using PHP) transforms your PHP application into a Model Context Protocol (MCP) server that AI assistants like Claude can connect to and interact with. Think of it as making your website conversational - AI can access your content, answer support questions, help with presales, and even process signups through natural conversation.

Instead of building complex integrations, you write simple PHP functions that become AI-accessible tools. WaaSuP handles all the protocol complexity, authentication, and communication.

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

**Learn MCP Integration**: Visit [How to Connect to MCP Servers](https://seolinkmap.com/documentation/how-to-connect-to-mcp-servers) for step-by-step instructions on connecting your AI tools to MCP servers.

---

## Prerequisites

- **PHP 8.1+** with PDO extension
- **Composer** for dependency management
- **MySQL/PostgreSQL** database (recommended for production)
- **Web server** (Apache/Nginx) or PHP built-in server for development

## Installation

Add WaaSuP to your project using Composer:

```bash
composer require seolinkmap/waasup
```

For production deployments, you'll also want a PSR-3 logger:

```bash
composer require monolog/monolog
```

If you're using Slim Framework or Laravel, install their packages:

```bash
# For Slim Framework
composer require slim/slim slim/psr7

# For Laravel (9.0+)
# WaaSuP auto-registers with Laravel's service container
```

## Database Setup

WaaSuP requires database tables for operation. All tables are required, but you can map them to your existing tables if you already have user management, OAuth, or organization systems.

### Review the Schema

Open the schema file to review the complete table structures:

[View database-schema.sql](../examples/database/database-schema.sql)

This file contains schemas for both MySQL and PostgreSQL. Copy the appropriate sections for your database and customize as needed.

### For New Installations

If starting fresh, import all tables from the schema file. The tables handle:
- MCP session management
- Message queuing between client and server
- OAuth authentication flows
- User and organization management
- Various response caching mechanisms

```sql
-- Copy and run the MySQL or PostgreSQL sections from database-schema.sql
-- Customize table names, add your own fields, adjust as needed
```

### For Existing Applications

If you already have user, organization, or OAuth tables:
1. Create all the MCP-specific tables
2. Configure WaaSuP to use your existing tables where applicable

See [Configuration](configuration.md) for details on table and field mapping to integrate with your existing database structure.

## Public MCP Server (Authless Mode)

A public MCP server makes your website's knowledge and services available to AI assistants, just like your website is available to browsers. Perfect for documentation, support, FAQs, and public services.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Seolinkmap\Waasup\MCPSaaSServer;
use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;

// 1. Connect to your database
$pdo = new PDO('mysql:host=localhost;dbname=your_db', 'user', 'pass');
$storage = new DatabaseStorage($pdo);

// 2. Create registries (these hold your tools, prompts, resources)
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// 3. Configuration for public/authless mode
$config = [
    'server_info' => [
        'name' => 'YourWebsite.com Support & Info',
        'version' => '1.0.0'
    ],
    'auth' => [
        'authless' => true  // Public mode - no authentication required
    ]
];

// 4. Create the MCP provider
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    new ResponseFactory(),
    new StreamFactory(),
    $config
);

// 5. Set up HTTP routing with Slim
$app = AppFactory::create();

// Public MCP endpoint - share this URL widely!
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp-public',
    [$mcpProvider, 'handleMCP']);

// 6. Add tools that make your website conversational
$toolRegistry->register('search_knowledge_base', function($params, $context) {
    $query = $params['query'] ?? '';
    // Your search logic here
    return [
        'results' => searchYourContent($query)
    ];
}, [
    'description' => 'Search our documentation and support articles',
    'inputSchema' => [
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Search query']
        ],
        'required' => ['query']
    ]
]);

// 7. Run the server
$app->run();
```

Your public MCP endpoint: `https://yourwebsite.com/mcp-public`

**Share this URL everywhere!** Add it to your website, documentation, social profiles - anywhere you want AI to learn about your services.

## Private MCP Server (Multi-tenant SaaS)

For SaaS applications with multiple organizations and users, requiring authentication for internal tools and customer data:

```php
// Configuration for authenticated mode (default)
$config = [
    'server_info' => [
        'name' => 'SaaS Platform MCP',
        'version' => '1.0.0'
    ],
    'base_url' => 'https://your-domain.com'
    // auth.authless defaults to false - authentication required
];

// Set up with authentication middleware
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

// Add tools for authenticated users
$toolRegistry->register('get_customer_data', function($params, $context) {
    $agencyId = $context['context_data']['id'];
    // Access customer data based on authenticated agency
    return getCustomerData($agencyId);
}, [
    'description' => 'Get customer data for authenticated organization'
]);
```

See [Authentication](authentication.md) for complete OAuth 2.1 setup.

## Testing Your Server

Test your MCP server is responding:

```bash
# For public/authless server
curl -X POST https://yourwebsite.com/mcp-public \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {}
    },
    "id": 1
  }'

# For authenticated server
curl -X POST https://your-domain.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {}
    },
    "id": 1
  }'
```

## Framework Integration

### Laravel

WaaSuP auto-registers with Laravel. Add to your routes:

```php
// routes/web.php
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;

// Public endpoint
Route::match(['GET', 'POST', 'OPTIONS'],
    '/mcp-public',
    [LaravelMCPProvider::class, 'handleMCP']
);

// Private endpoint with auth
Route::middleware('mcp.auth')->group(function () {
    Route::match(['GET', 'POST', 'OPTIONS'],
        '/mcp/{agencyUuid}/{sessID?}',
        [LaravelMCPProvider::class, 'handleMCP']
    );
});
```

### Standalone PSR-7

Use any PSR-7 compatible framework:

```php
$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $config
);

$response = $mcpServer->handle($request, $response);
```

## Directory Structure

```
your-project/
├── composer.json
├── public/
│   └── index.php           # Your MCP server entry point
├── src/
│   └── Tools/              # Your custom tools
│       ├── SearchTool.php
│       ├── CustomerTool.php
│       └── SupportTool.php
├── config/
│   └── mcp.php             # Configuration file
└── vendor/
    └── seolinkmap/waasup/  # WaaSuP library
```

## Next Steps

- **[Build custom tools](tools.md)** - Create tools that make your application conversational
- **[Configure your server](configuration.md)** - Customize behavior and integrate with existing tables
- **[Set up authentication](authentication.md)** - Add OAuth 2.1 for private tools
- **[Explore the API](api-reference.md)** - Understand the full MCP protocol

## Common Issues

### Database connection errors
Verify your PDO connection string and that all required tables exist. You can map to existing tables - see [Configuration](configuration.md).

### No tools showing up
Ensure tools are registered before calling `$app->run()`.

### Using existing user/auth tables
See [Configuration](configuration.md) for complete table and field mapping options.

## Support

- **AI-Powered Help**: Connect to `https://seolinkmap.com/mcp-repo` for instant assistance
- **Documentation**: [GitHub Wiki](https://github.com/seolinkmap/waasup/wiki)
- **Issues**: [GitHub Issues](https://github.com/seolinkmap/waasup/issues)
- **Examples**: See [examples directory](../examples/) for complete working examples

---

Ready to build? Continue to [Building Tools](tools.md) →
