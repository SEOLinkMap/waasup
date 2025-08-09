# Authless Server Example

This example shows how to create a public MCP server using WaaSuP that doesn't require authentication. Perfect for documentation, support systems, public APIs, and marketing websites.

## What is an Authless Server?

An authless MCP server is like a public website - anyone can access it without logging in. It's perfect for:
- **Public documentation** - Help articles, FAQs, API documentation
- **Marketing websites** - Product information, pricing, company details
- **Support systems** - Public troubleshooting tools, status pages
- **Public APIs** - Weather data, news feeds, publicly available information

## Complete Working Example

Here's a minimal but complete authless MCP server:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Seolinkmap\Waasup\Storage\DatabaseStorage;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;
use Seolinkmap\Waasup\Prompts\Registry\PromptRegistry;
use Seolinkmap\Waasup\Resources\Registry\ResourceRegistry;
use Seolinkmap\Waasup\Integration\Slim\SlimMCPProvider;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

// 1. Database Setup (required for MCP protocol)
// First, create the required tables using the schema from WaaSuP documentation
$pdo = new PDO('mysql:host=localhost;dbname=your_database', 'username', 'password');
$storage = new DatabaseStorage($pdo, [
    'database' => ['table_prefix' => 'mcp_']
]);

// 2. Create registries for tools, prompts, and resources
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// 3. Configuration for authless (public) mode
$config = [
    'server_info' => [
        'name' => 'My Company Public API',
        'version' => '1.0.0'
    ],
    'auth' => [
        'authless' => true  // This makes it public - no authentication required
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

// 5. Set up HTTP routing with Slim Framework
$app = AppFactory::create();

// CORS handling for web clients
$app->options('/{routes:.+}', function ($request, $response) {
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
});

// 6. Public MCP endpoint - share this URL with AI assistants!
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp-public', [$mcpProvider, 'handleMCP']);

// 7. Register your tools (the AI-accessible functions)
$toolRegistry->register('hello_world', function($params, $context) {
    $name = $params['name'] ?? 'World';

    return [
        'message' => "Hello, {$name}! Welcome to our MCP server.",
        'timestamp' => date('c'),
        'server' => 'My Company Public API'
    ];
}, [
    'description' => 'Say hello to someone',
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

$toolRegistry->register('website_info', function($params, $context) {
    return [
        'company' => [
            'name' => 'Your Amazing Company',
            'tagline' => 'Making the world better with AI',
            'founded' => '2020',
            'employees' => '50-100'
        ],
        'pricing' => [
            'starter' => [
                'name' => 'Starter Plan',
                'price' => '$29/month',
                'features' => [
                    'Up to 1,000 API calls',
                    'Email support',
                    'Basic analytics'
                ]
            ],
            'professional' => [
                'name' => 'Professional Plan',
                'price' => '$99/month',
                'features' => [
                    'Up to 10,000 API calls',
                    'Priority support',
                    'Advanced analytics',
                    'Custom integrations'
                ]
            ],
            'enterprise' => [
                'name' => 'Enterprise Plan',
                'price' => 'Contact sales',
                'features' => [
                    'Unlimited API calls',
                    'Dedicated support',
                    'Custom development',
                    'SLA guarantee'
                ]
            ]
        ],
        'contact' => [
            'email' => 'hello@yourcompany.com',
            'phone' => '+1 (555) 123-4567',
            'address' => '123 Main St, San Francisco, CA 94102'
        ],
        'api_status' => 'operational',
        'last_updated' => date('c')
    ];
}, [
    'description' => 'Get comprehensive information about our company, pricing, and services',
    'inputSchema' => [
        'type' => 'object',
        'properties' => []  // No parameters needed
    ]
]);

// 8. Run the server
$app->run();
```

## How to Use This Server

### 1. Share the URL

Your MCP endpoint will be available at:
```
https://yourwebsite.com/mcp-public
```

**Share this URL everywhere!** Add it to:
- Your website footer
- Documentation pages
- Social media profiles
- Developer resources
- AI assistant marketplaces

### 2. Connect with AI Assistants

Users can connect this URL to AI assistants like:
- **Claude.ai** - Add as a custom MCP server
- **GPT with MCP plugins** - Connect via the URL
- **Custom AI applications** - Use the MCP protocol

### 3. Test Your Server

Test that it's working:

```bash
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
```

## Framework Integration Examples

### Laravel Integration

```php
// routes/web.php
use Seolinkmap\Waasup\Integration\Laravel\LaravelMCPProvider;

// Public MCP endpoint
Route::match(['GET', 'POST', 'OPTIONS'], '/mcp-public', [LaravelMCPProvider::class, 'handleMCP']);

// In a service provider, register your tools
public function boot()
{
    $toolRegistry = app(ToolRegistry::class);

    $toolRegistry->register('hello_world', function($params, $context) {
        return ['message' => "Hello from Laravel!"];
    }, [
        'description' => 'Laravel-powered greeting'
    ]);
}
```

### Standalone PHP

```php
// For any PSR-7 compatible framework
$mcpServer = new MCPSaaSServer(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    $config
);

// Handle the request
$response = $mcpServer->handle($request, $response);
```



## Understanding the Code

### Why Authless Mode?

```php
'auth' => [
    'authless' => true  // Disables all authentication
]
```

This tells WaaSuP to operate in public mode - like a website that anyone can visit. No OAuth, no login required.

### Tool Registration

```php
$toolRegistry->register('tool_name', function($params, $context) {
    // Your business logic here
    return ['result' => 'data'];
}, [
    'description' => 'What this tool does',
    'inputSchema' => [/* parameter schema */]
]);
```

Tools are functions that AI assistants can call. The `$params` contains user input, `$context` has system information.

### Database Requirement

```php
$storage = new DatabaseStorage($pdo, [
    'database' => ['table_prefix' => 'mcp_']
]);
```

Even public servers need a database for:
- Session management (MCP protocol requirement)
- Message queuing between client and server
- Protocol state management

You'll need to create the required database tables first using the schema provided in the WaaSuP documentation.

### URL Structure

- **POST requests**: JSON-RPC method calls (tool execution, initialization)
- **GET requests**: SSE (Server-Sent Events) and Streamable HTTP transport for real-time messaging
- **OPTIONS requests**: CORS preflight handling

## Adding More Tools

Here are some example tools you might add:

```php
// Company status tool
$toolRegistry->register('service_status', function($params, $context) {
    return [
        'api' => 'operational',
        'database' => 'operational',
        'cdn' => 'degraded',
        'last_incident' => '2024-01-15T10:30:00Z',
        'uptime' => '99.9%'
    ];
}, [
    'description' => 'Get current service status and uptime information'
]);

// Documentation search
$toolRegistry->register('search_docs', function($params, $context) {
    $query = $params['query'] ?? '';

    // Your search logic here
    $results = searchDocumentation($query);

    return [
        'query' => $query,
        'results' => $results,
        'total_found' => count($results)
    ];
}, [
    'description' => 'Search our documentation and help articles',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Search terms'
            ]
        ],
        'required' => ['query']
    ]
]);

// Product information
$toolRegistry->register('product_info', function($params, $context) {
    $productId = $params['product_id'] ?? null;

    if (!$productId) {
        return ['error' => 'Product ID required'];
    }

    // Your product lookup logic
    return getProductInformation($productId);
}, [
    'description' => 'Get detailed information about our products',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'product_id' => [
                'type' => 'string',
                'description' => 'Product identifier'
            ]
        ],
        'required' => ['product_id']
    ]
]);
```

## Security Considerations

Even though it's public, follow these guidelines:

### ✅ Safe for Public Tools
- Company information and pricing
- Product catalogs and features
- Documentation and help articles
- Status pages and uptime info
- General-purpose utilities

### ❌ Never Expose Publicly
- User account information
- Internal system data
- Private business metrics
- Customer data
- Administrative functions

### Input Validation

```php
$toolRegistry->register('safe_tool', function($params, $context) {
    // Always validate and sanitize inputs
    $query = $params['query'] ?? '';

    if (strlen($query) > 100) {
        return ['error' => 'Query too long (max 100 characters)'];
    }

    if (empty($query)) {
        return ['error' => 'Query cannot be empty'];
    }

    // Sanitize for your use case
    $query = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');

    return performSafeOperation($query);
});
```

## Deployment

### Production Checklist

- ✅ Create required database tables using the WaaSuP schema
- ✅ Use HTTPS for your MCP endpoint
- ✅ Set up proper CORS headers
- ✅ Configure database with appropriate permissions
- ✅ Add rate limiting to prevent abuse
- ✅ Monitor server performance and logs
- ✅ Set up automated backups
- ✅ Test with multiple AI assistants

### Environment Variables

```php
// Use environment variables for sensitive config
$config = [
    'server_info' => [
        'name' => $_ENV['MCP_SERVER_NAME'] ?? 'Public API',
        'version' => $_ENV['MCP_SERVER_VERSION'] ?? '1.0.0'
    ],
    'auth' => [
        'authless' => true
    ]
];

$pdo = new PDO(
    $_ENV['DATABASE_URL'],
    $_ENV['DATABASE_USER'],
    $_ENV['DATABASE_PASS']
);
```

## Next Steps

1. **Create Database Tables**: Use the WaaSuP schema to create required tables
2. **Start Simple**: Copy the basic example and get it running
3. **Add Your Tools**: Replace the example tools with your actual business logic
4. **Test Thoroughly**: Use curl and AI assistants to test functionality
5. **Share Widely**: Add your MCP URL to websites, docs, and profiles
6. **Monitor Usage**: Track which tools are being called and how often

For servers requiring user authentication, see the WaaSuP authentication documentation.

## Troubleshooting

**"No tools showing up"**
Ensure tools are registered before calling `$app->run()`.

**"Connection refused"**
Check that your web server is running and the URL is accessible.

**"Database errors"**
Verify your PDO connection and ensure you've created the required database tables using the WaaSuP schema.

**"CORS errors in browser"**
Make sure the OPTIONS route handler is set up correctly.

Your authless MCP server makes your business knowledge and services accessible to AI assistants worldwide. Share your URL and let AI help your users!
