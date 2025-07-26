# Getting Started

This guide will help you set up and run the MCP SaaS Server in your environment.

## Prerequisites

- **PHP 8.1+** with PDO extension
- **Composer** for dependency management
- **MySQL/PostgreSQL** database (recommended for production)
- **Web server** (Apache/Nginx) or PHP built-in server for development

## Installation

### 1. Install via Composer

```bash
# Install the package
composer require seolinkmap/waasup

# Install PSR-17 factories (required)
composer require slim/psr7

# Optional: Add logging
composer require monolog/monolog

# Optional: Add Slim framework for easy setup
composer require slim/slim
```

# Getting Started

This guide will help you set up and run the MCP SaaS Server in your environment.

## Prerequisites

- **PHP 8.1+** with PDO extension
- **Composer** for dependency management
- **MySQL/PostgreSQL** database (recommended for production)
- **Web server** (Apache/Nginx) or PHP built-in server for development

## Installation

### 1. Install via Composer

```bash
# Install the package
composer require seolinkmap/waasup

# Install PSR-17 factories (required)
composer require slim/psr7

# Optional: Add logging
composer require monolog/monolog

# Optional: Add Slim framework for easy setup
composer require slim/slim
```

### 2. Database Setup

You have two options for database setup:

#### Option A: New Installation (Recommended)
Create your database and tables using the default schema:

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE mcp_server"

# Import the complete schema
mysql -u root -p mcp_server < examples/database/database-schema.sql
```

#### Option B: Existing Database Integration
If you have existing user/agency tables you want to integrate with, see the **[Custom Table Configuration Guide](../examples/database/custom-table-configuration.md)** for detailed instructions on mapping your existing tables to WaaSuP's requirements.


### 3. Create Your First Agency

```sql
-- Add a test agency
INSERT INTO mcp_agencies (uuid, name, active, created_at)
VALUES ('550e8400-e29b-41d4-a716-446655440000', 'My Test Agency', 1, NOW());

-- Create an access token for testing
INSERT INTO mcp_oauth_tokens (
    access_token, scope, expires_at, agency_id, revoked, created_at
) VALUES (
    'test-token-12345',
    'mcp:read mcp:write',
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    1,
    0,
    NOW()
);
```

## Quick Start Examples

### Basic Slim Server

Create `server.php`:

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
$pdo = new PDO('mysql:host=localhost;dbname=mcp_server', 'username', 'password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Initialize components
$storage = new DatabaseStorage($pdo, ['table_prefix' => 'mcp_']);
$toolRegistry = new ToolRegistry();
$promptRegistry = new PromptRegistry();
$resourceRegistry = new ResourceRegistry();

// Add a simple tool
$toolRegistry->register('echo', function($params, $context) {
    return [
        'message' => $params['message'] ?? 'Hello!',
        'timestamp' => date('c'),
        'context_available' => !empty($context)
    ];
}, [
    'description' => 'Echo a message back',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'Message to echo']
        ],
        'required' => ['message']
    ]
]);

// Configuration
$config = [
    'supported_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],
    'server_info' => [
        'name' => 'My MCP Server',
        'version' => '1.0.0'
    ],
    'auth' => [
        'context_types' => ['agency'],
        'base_url' => 'http://localhost:8080'
    ]
];

// Create MCP provider
$mcpProvider = new SlimMCPProvider(
    $storage,
    $toolRegistry,
    $promptRegistry,
    $resourceRegistry,
    new ResponseFactory(),
    new StreamFactory(),
    $config
);

// Setup Slim app
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// OAuth discovery
$app->get('/.well-known/oauth-authorization-server',
    [$mcpProvider, 'handleAuthDiscovery']);

// Main MCP endpoint - handles both POST (commands) and GET (SSE)
$app->map(['GET', 'POST', 'OPTIONS'], '/mcp/{agencyUuid}[/{sessID}]',
    [$mcpProvider, 'handleMCP'])
    ->add($mcpProvider->getAuthMiddleware());

$app->run();
```

Run the server:
```bash
php -S localhost:8080 server.php
```

### Memory Storage (Development/Testing)

For quick testing without a database:

```php
use Seolinkmap\Waasup\Storage\MemoryStorage;

$storage = new MemoryStorage();

// Add test data
$storage->addContext('550e8400-e29b-41d4-a716-446655440000', 'agency', [
    'id' => 1,
    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'name' => 'Test Agency',
    'active' => true
]);

$storage->addToken('test-token-12345', [
    'access_token' => 'test-token-12345',
    'agency_id' => 1,
    'scope' => 'mcp:read mcp:write',
    'expires_at' => time() + 3600,
    'revoked' => false
]);
```

## Understanding the Async Protocol

This server implements **asynchronous responses** using the MCP protocol:

1. **POST** to MCP URL with `initialize` → **Direct JSON response** with session ID in header
2. **POST** to MCP URL with other commands → Returns `{"status": "queued"}`
3. **GET** to **same MCP URL** → Establishes **SSE connection** (2024-11-05) or **Streamable HTTP** (2025-03-26+) that delivers actual responses

## Testing Your Setup

### 1. Check Discovery Endpoint

```bash
curl http://localhost:8080/.well-known/oauth-authorization-server
```

Expected response:
```json
{
  "issuer": "http://localhost:8080",
  "authorization_endpoint": "http://localhost:8080/oauth/authorize",
  "token_endpoint": "http://localhost:8080/oauth/token",
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "response_types_supported": ["code"],
  "token_endpoint_auth_methods_supported": ["client_secret_post", "none"],
  "code_challenge_methods_supported": ["S256"],
  "response_modes_supported": ["query"],
  "registration_endpoint": "http://localhost:8080/oauth/register",
  "scopes_supported": ["mcp:read"]
}
```

### 2. Test MCP Initialize (Direct Response)

```bash
curl -i -X POST http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer test-token-12345" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-03-26",
      "capabilities": {},
      "clientInfo": {
        "name": "Test Client",
        "version": "1.0.0"
      }
    },
    "id": 1
  }'
```

Expected response includes `Mcp-Session-Id` header:
```
HTTP/1.1 200 OK
Mcp-Session-Id: sess_1234567890abcdef
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "result": {
    "protocolVersion": "2025-03-26",
    "capabilities": {
      "tools": {"listChanged": true},
      "prompts": {"listChanged": true},
      "resources": {"subscribe": false, "listChanged": true},
      "completions": true
    },
    "serverInfo": {
      "name": "My MCP Server",
      "version": "1.0.0"
    }
  },
  "id": 1
}
```

### 3. Test Async Command Flow

**Step 1: Send Command (Returns Queued)**
```bash
curl -X POST http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer test-token-12345" \
  -H "Mcp-Session-Id: sess_1234567890abcdef" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "id": 2
  }'

# Response: {"status": "queued"}
```

**Step 2: Establish Streaming Connection (GET to same URL)**
```bash
# In a separate terminal, connect to same URL with GET
curl -N http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer test-token-12345" \
  -G -d "session_id=sess_1234567890abcdef"

# Will output (SSE format for 2024-11-05):
# event: endpoint
# data: http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000/sess_1234567890abcdef
#
# event: message
# data: {"jsonrpc":"2.0","result":{"tools":[...]},"id":2}

# Or for 2025-03-26+ (Streamable HTTP format):
# {"jsonrpc":"2.0","method":"notifications/connection","params":{"status":"connected"}}
# {"jsonrpc":"2.0","result":{"tools":[...]},"id":2}
```

### 4. Call a Tool

```bash
# Send command
curl -X POST http://localhost:8080/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer test-token-12345" \
  -H "Mcp-Session-Id: sess_1234567890abcdef" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "echo",
      "arguments": {
        "message": "Hello from MCP!"
      }
    },
    "id": 3
  }'

# Returns: {"status": "queued"}
# Response appears in streaming connection:
# {"jsonrpc":"2.0","result":{"content":[{"type":"text","text":"{\"message\":\"Hello from MCP!\",\"timestamp\":\"...\"}"}]},"id":3}
```

## Development Workflow

### 1. Add Built-in Tools

The server includes ready-to-use tools:

```php
use Seolinkmap\Waasup\Tools\Built\PingTool;
use Seolinkmap\Waasup\Tools\Built\ServerInfoTool;

$toolRegistry->registerTool(new PingTool());
$toolRegistry->registerTool(new ServerInfoTool($config));
```

### 2. Create Custom Tools

Simple callable approach:

```php
$toolRegistry->register('get_weather', function($params, $context) {
    $location = $params['location'] ?? 'Unknown';

    // Your weather API logic here
    return [
        'location' => $location,
        'temperature' => '22°C',
        'condition' => 'Sunny',
        'agency_id' => $context['context_data']['id'] ?? 'unknown'
    ];
}, [
    'description' => 'Get weather for a location',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'Location name']
        ],
        'required' => ['location']
    ],
    'annotations' => [  // Tool annotations (2025-03-26+)
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => true
    ]
]);
```

Class-based approach:

```php
use Seolinkmap\Waasup\Tools\AbstractTool;

class DatabaseTool extends AbstractTool
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        parent::__construct(
            'query_stats',
            'Get database statistics',
            [
                'properties' => [
                    'table' => ['type' => 'string', 'description' => 'Table name']
                ]
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        // Safe implementation - never execute raw SQL from params
        $allowedTables = ['mcp_agencies', 'mcp_users'];
        $table = $parameters['table'] ?? '';

        if (!in_array($table, $allowedTables)) {
            throw new \InvalidArgumentException('Table not allowed');
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM `{$table}`");
        $stmt->execute();
        $result = $stmt->fetch();

        return ['table' => $table, 'count' => $result['count']];
    }
}

$toolRegistry->registerTool(new DatabaseTool($pdo));
```

### 3. Audio Content Tools (2025-03-26+)

```php
use Seolinkmap\Waasup\Content\AudioContentHandler;

$toolRegistry->register('text_to_speech', function($params, $context) {
    $text = $params['text'] ?? '';

    if (empty($text)) {
        return ['error' => 'No text provided'];
    }

    // Example: Generate audio file (replace with your TTS implementation)
    $audioPath = generateSpeechFile($text);

    if (!file_exists($audioPath)) {
        return ['error' => 'Audio generation failed'];
    }

    return [
        'content' => [
            ['type' => 'text', 'text' => 'Generated speech audio:'],
            AudioContentHandler::createFromFile($audioPath, 'speech.mp3')
        ]
    ];
}, [
    'description' => 'Convert text to speech audio',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string', 'description' => 'Text to convert to speech']
        ],
        'required' => ['text']
    ]
]);

// Helper function (implement your TTS logic)
function generateSpeechFile(string $text): string {
    // This is a placeholder - implement your actual TTS logic
    $tempFile = tempnam(sys_get_temp_dir(), 'tts_') . '.mp3';

    // Example using system TTS (Linux)
    // exec("espeak '{$text}' --stdout | lame -r -s 22050 -m m - '{$tempFile}'");

    return $tempFile;
}
```

### 4. Add Prompts

```php
$promptRegistry->register('code_review', function($arguments, $context) {
    $code = $arguments['code'] ?? '';
    $language = $arguments['language'] ?? 'code';

    return [
        'description' => 'Code review prompt',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Please review this {$language} code and provide feedback:\n\n```{$language}\n{$code}\n```"
                    ]
                ]
            ]
        ]
    ];
}, [
    'description' => 'Generate a code review prompt',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Code to review'],
            'language' => ['type' => 'string', 'description' => 'Programming language']
        ],
        'required' => ['code']
    ]
]);
```

### 5. Add Resources

```php
$resourceRegistry->register('server://health', function($uri, $context) {
    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode([
                    'status' => 'healthy',
                    'timestamp' => date('c'),
                    'version' => '1.0.0',
                    'agency' => $context['context_data']['name'] ?? 'Unknown'
                ])
            ]
        ]
    ];
}, [
    'name' => 'Server Health',
    'description' => 'Server health status',
    'mimeType' => 'application/json'
]);

// Resource template
$resourceRegistry->registerTemplate('file://{path}', function($uri, $context) {
    $path = str_replace('file://', '', $uri);
    $safePath = basename($path); // Simple security measure

    return [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => "Content for file: {$safePath}\n(Implement actual file reading)"
            ]
        ]
    ];
}, [
    'name' => 'File Resource',
    'description' => 'Read file contents from the server',
    'mimeType' => 'text/plain'
]);
```

### 6. Elicitation (User Input) - 2025-06-18

```php
// Example tool that requests structured user input
$toolRegistry->register('collect_user_info', function($params, $context) use ($mcpProvider) {
    $sessionId = $context['session_id'] ?? null;

    if (!$sessionId) {
        return ['error' => 'Session required for elicitation'];
    }

    // Request structured input from user
    $requestId = $mcpProvider->getServer()->requestElicitation(
        $sessionId,
        'Please provide your contact information',
        [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string']
            ],
            'required' => ['name', 'email']
        ]
    );

    return [
        'message' => 'User input requested',
        'request_id' => $requestId,
        'status' => 'waiting_for_input'
    ];
}, [
    'description' => 'Collect structured user information via elicitation',
    'inputSchema' => ['type' => 'object']
]);
```

## JavaScript Client Example

```javascript
class MCPClient {
    constructor(baseUrl, accessToken, protocolVersion = '2025-03-26') {
        this.baseUrl = baseUrl;
        this.accessToken = accessToken;
        this.protocolVersion = protocolVersion;
        this.sessionId = null;
        this.eventSource = null;
        this.pendingRequests = new Map();
        this.requestIdCounter = 0;
    }

    async initialize(agencyId) {
        const headers = {
            'Authorization': `Bearer ${this.accessToken}`,
            'Content-Type': 'application/json'
        };

        // Add protocol version header for 2025-06-18
        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

        const response = await fetch(`${this.baseUrl}/mcp/${agencyId}`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
                jsonrpc: '2.0',
                method: 'initialize',
                params: {
                    protocolVersion: this.protocolVersion,
                    capabilities: {},
                    clientInfo: { name: 'JS Client', version: '1.0.0' }
                },
                id: 1
            })
        });

        if (!response.ok) {
            if (response.status === 401) {
                const error = await response.json();
                if (error.error?.data?.oauth) {
                    console.log('OAuth endpoints available:', error.error.data.oauth);
                    throw new Error('Authentication required - use OAuth flow');
                }
            }
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        this.sessionId = response.headers.get('Mcp-Session-Id');
        return response.json();
    }

    connectStreaming(agencyId) {
        const url = `${this.baseUrl}/mcp/${agencyId}?session_id=${this.sessionId}`;

        const headers = {
            'Authorization': `Bearer ${this.accessToken}`
        };

        // Add protocol version header for 2025-06-18
        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

        // Use appropriate transport based on protocol version
        if (this.shouldUseStreamableHTTP()) {
            return this.connectStreamableHTTP(url, headers);
        } else {
            return this.connectSSE(url, headers);
        }
    }

    shouldUseStreamableHTTP() {
        return ['2025-03-26', '2025-06-18'].includes(this.protocolVersion);
    }

    connectSSE(url, headers) {
        this.eventSource = new EventSource(url, { headers });

        this.eventSource.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            this.handleResponse(data);
        });

        this.eventSource.addEventListener('error', (event) => {
            console.error('SSE connection error:', event);
        });
    }

    connectStreamableHTTP(url, headers) {
        fetch(url, {
            method: 'GET',
            headers: headers
        }).then(response => {
            const reader = response.body.getReader();

            const processStream = async () => {
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const text = new TextDecoder().decode(value);
                    const lines = text.split('\n');

                    for (const line of lines) {
                        if (line.trim()) {
                            try {
                                const data = JSON.parse(line);
                                this.handleResponse(data);
                            } catch (e) {
                                // Ignore parse errors for partial chunks
                            }
                        }
                    }
                }
            };

            processStream();
        });
    }

    handleResponse(data) {
        if (data.id && this.pendingRequests.has(data.id)) {
            const { resolve } = this.pendingRequests.get(data.id);
            this.pendingRequests.delete(data.id);
            resolve(data);
        } else if (data.method === 'notifications/progress') {
            console.log('Progress:', data.params);
        }
    }

    async call(agencyId, method, params = {}) {
        const id = ++this.requestIdCounter;

        const promise = new Promise((resolve, reject) => {
            this.pendingRequests.set(id, { resolve, reject });

            setTimeout(() => {
                if (this.pendingRequests.has(id)) {
                    this.pendingRequests.delete(id);
                    reject(new Error('Request timeout'));
                }
            }, 30000);
        });

        const headers = {
            'Authorization': `Bearer ${this.accessToken}`,
            'Mcp-Session-Id': this.sessionId,
            'Content-Type': 'application/json'
        };

        // Add protocol version header for 2025-06-18
        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

        // POST request returns {"status": "queued"}
        await fetch(`${this.baseUrl}/mcp/${agencyId}`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
                jsonrpc: '2.0',
                method,
                params,
                id
            })
        });

        return promise; // Resolves when response comes via streaming
    }
}

// Usage
const client = new MCPClient('http://localhost:8080', 'test-token-12345', '2025-03-26');
await client.initialize('550e8400-e29b-41d4-a716-446655440000');
client.connectStreaming('550e8400-e29b-41d4-a716-446655440000');

const toolsList = await client.call('550e8400-e29b-41d4-a716-446655440000', 'tools/list');
console.log(toolsList.result.tools);
```

## Troubleshooting

### Common Issues

**"Authentication required" Error**
- Check your access token is valid and not expired
- Verify the Authorization header format: `Bearer your-token`

**"Session required" Error**
- Include session ID from initialize response in subsequent requests
- Use either `Mcp-Session-Id` header or URL parameter

**"Context not found" Error**
- Verify your agency UUID exists in the database
- Check the agency is marked as active

**Streaming Connection Issues**
- Ensure you're using GET request to the same MCP URL
- Include session_id as query parameter
- Verify Authorization header is included
- For 2025-06-18, ensure MCP-Protocol-Version header matches

**Protocol Version Errors**
- Check that requested features are supported in your protocol version
- Tool annotations only available in 2025-03-26+
- Audio content only available in 2025-03-26+
- Elicitation only available in 2025-06-18

### Debug Mode

Enable detailed logging:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Pass logger to SlimMCPProvider
$mcpProvider = new SlimMCPProvider(
    $storage, $toolRegistry, $promptRegistry, $resourceRegistry,
    $responseFactory, $streamFactory, $config, $logger
);
```

## Next Steps

1. **Production Setup**: Configure proper database and web server
2. **Authentication**: Set up OAuth providers for social login
3. **Framework Integration**: Use Laravel service provider for Laravel projects
4. **Custom Tools**: Build domain-specific tools for your use case
5. **Client Libraries**: Implement proper MCP client with streaming support
6. **Database Schema**: See [database-schema.md](database-schema.md) for complete table definitions

## Resources

- **Source Code**: [GitHub Repository](https://github.com/seolinkmap/waasup)
- **MCP Protocol**: [Anthropic MCP Specification](https://spec.modelcontextprotocol.io/)
- **Database Schema**: [database-schema.md](database-schema.md)
- **Configuration**: [configuration.md](configuration.md)
- **API Reference**: [api-reference.md](api-reference.md)
