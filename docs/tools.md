# Tools

Tools are executable functions that clients can call through the MCP server. This document covers how to create, register, and use tools in the MCP SaaS Server.

## Table of Contents

- [Overview](#overview)
- [Tool Response Format](#tool-response-format)
- [Built-in Tools](#built-in-tools)
- [Creating Tools](#creating-tools)
- [Tool Registry](#tool-registry)
- [Tool Annotations](#tool-annotations)
- [Audio Content Tools](#audio-content-tools)
- [Best Practices](#best-practices)
- [Examples](#examples)

## Overview

Tools in the MCP server allow clients to perform actions and retrieve data. Each tool has:

- **Name**: Unique identifier
- **Description**: Human-readable description
- **Input Schema**: JSON schema defining expected parameters
- **Handler**: Function that executes the tool logic
- **Annotations**: Hints about tool behavior (2025-03-26+)

### Tool Execution Flow

1. Client calls `tools/list` to discover available tools
2. Client calls `tools/call` with tool name and parameters
3. Server validates parameters against schema
4. Server executes tool handler with parameters and context
5. Server wraps result in MCP content format and returns via streaming

## Tool Response Format

**Important**: Tool functions return data as arrays/objects, but the MCP server wraps these results in content format and JSON-encodes them as text.

### What Your Tool Function Returns

```php
// Your tool returns this:
return [
    'status' => 'pong',
    'timestamp' => '2024-01-15T10:30:00Z',
    'message' => 'Hello server!'
];
```

### What Clients Actually Receive

```json
{
  "jsonrpc": "2.0",
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\n  \"status\": \"pong\",\n  \"timestamp\": \"2024-01-15T10:30:00Z\",\n  \"message\": \"Hello server!\"\n}"
      }
    ]
  },
  "id": 1
}
```

**Key Points:**
- Tool results are **JSON-encoded as text content**
- Clients must **parse the JSON text** to get structured data
- This applies to all tool responses, including errors

## Built-in Tools

The server includes ready-to-use tools for basic functionality.

### PingTool

Tests connectivity and server responsiveness.

```php
use Seolinkmap\Waasup\Tools\Built\PingTool;

$toolRegistry->registerTool(new PingTool());
```

**Usage:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "ping",
    "arguments": {
      "message": "Hello server!"
    }
  },
  "id": 1
}
```

**Tool Function Returns:**
```php
[
    'status' => 'pong',
    'timestamp' => '2024-01-15T10:30:00Z',
    'message' => 'Hello server!',
    'context_available' => true
]
```

**Client Receives (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"status\":\"pong\",\"timestamp\":\"2024-01-15T10:30:00Z\",\"message\":\"Hello server!\",\"context_available\":true}"
      }
    ]
  },
  "id": 1
}
```

### ServerInfoTool

Returns server information and configuration.

```php
use Seolinkmap\Waasup\Tools\Built\ServerInfoTool;

$toolRegistry->registerTool(new ServerInfoTool($config));
```

**Usage:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "server_info",
    "arguments": {
      "include_context": true
    }
  },
  "id": 1
}
```

## Creating Tools

There are two approaches to creating tools: callable functions and class-based tools.

### Callable Tools (Simple)

For straightforward tools, use callable registration:

```php
$toolRegistry->register('get_time', function($params, $context) {
    $timezone = $params['timezone'] ?? 'UTC';

    try {
        $date = new DateTime('now', new DateTimeZone($timezone));
        return [
            'current_time' => $date->format('c'),
            'timezone' => $timezone,
            'unix_timestamp' => $date->getTimestamp()
        ];
    } catch (Exception $e) {
        return [
            'error' => 'Invalid timezone',
            'timezone' => $timezone
        ];
    }
}, [
    'description' => 'Get current time in specified timezone',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'timezone' => [
                'type' => 'string',
                'description' => 'Timezone identifier (e.g., "America/New_York")',
                'default' => 'UTC'
            ]
        ]
    ],
    'annotations' => [  // Tool annotations (2025-03-26+)
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false
    ]
]);
```

### Class-based Tools (Advanced)

For complex tools with state or dependencies, use class-based approach:

```php
use Seolinkmap\Waasup\Tools\AbstractTool;

class WeatherTool extends AbstractTool
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = 'https://api.openweathermap.org/data/2.5';

        parent::__construct(
            'get_weather',
            'Get current weather for a location',
            [
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name or coordinates'
                    ],
                    'units' => [
                        'type' => 'string',
                        'enum' => ['metric', 'imperial', 'kelvin'],
                        'default' => 'metric',
                        'description' => 'Temperature units'
                    ]
                ],
                'required' => ['location']
            ],
            [  // Tool annotations (2025-03-26+)
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => false, // Weather data can change
                'openWorldHint' => true   // Accesses external API
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        $location = $parameters['location'];
        $units = $parameters['units'] ?? 'metric';

        try {
            $url = "{$this->baseUrl}/weather?" . http_build_query([
                'q' => $location,
                'appid' => $this->apiKey,
                'units' => $units
            ]);

            $response = file_get_contents($url);
            if ($response === false) {
                throw new \Exception('Failed to fetch weather data');
            }

            $data = json_decode($response, true);

            if (!$data || isset($data['cod']) && $data['cod'] !== 200) {
                return [
                    'error' => 'Weather data not found',
                    'location' => $location
                ];
            }

            return [
                'location' => $data['name'],
                'country' => $data['sys']['country'],
                'temperature' => $data['main']['temp'],
                'feels_like' => $data['main']['feels_like'],
                'humidity' => $data['main']['humidity'],
                'description' => $data['weather'][0]['description'],
                'units' => $units,
                'timestamp' => date('c')
            ];
        } catch (Exception $e) {
            return [
                'error' => 'Failed to fetch weather data',
                'message' => $e->getMessage()
            ];
        }
    }
}

// Register the tool
$toolRegistry->registerTool(new WeatherTool($_ENV['WEATHER_API_KEY']));
```

### Database Tool Example

```php
class DatabaseQueryTool extends AbstractTool
{
    private PDO $pdo;
    private array $allowedTables;

    public function __construct(PDO $pdo, array $allowedTables = [])
    {
        $this->pdo = $pdo;
        $this->allowedTables = $allowedTables;

        parent::__construct(
            'query_database',
            'Execute safe database queries',
            [
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name to query'
                    ],
                    'columns' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Columns to select (default: all)'
                    ],
                    'where' => [
                        'type' => 'object',
                        'description' => 'WHERE conditions as key-value pairs'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 10,
                        'description' => 'Maximum number of rows'
                    ]
                ],
                'required' => ['table']
            ],
            [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        $table = $parameters['table'];
        $columns = $parameters['columns'] ?? ['*'];
        $where = $parameters['where'] ?? [];
        $limit = $parameters['limit'] ?? 10;

        // Security: Check allowed tables
        if (!empty($this->allowedTables) && !in_array($table, $this->allowedTables)) {
            return ['error' => 'Table not allowed'];
        }

        // Security: Validate table name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            return ['error' => 'Invalid table name'];
        }

        try {
            // Build query safely
            $columnList = implode(', ', array_map(function($col) {
                return $col === '*' ? '*' : "`{$col}`";
            }, $columns));

            $sql = "SELECT {$columnList} FROM `{$table}`";
            $params = [];

            if (!empty($where)) {
                $conditions = [];
                foreach ($where as $column => $value) {
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                        $conditions[] = "`{$column}` = :where_{$column}";
                        $params["where_{$column}"] = $value;
                    }
                }
                if (!empty($conditions)) {
                    $sql .= ' WHERE ' . implode(' AND ', $conditions);
                }
            }

            $sql .= " LIMIT :limit";
            $params['limit'] = $limit;

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":{$key}", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'table' => $table,
                'row_count' => count($results),
                'data' => $results,
                'query_timestamp' => date('c')
            ];
        } catch (PDOException $e) {
            return [
                'error' => 'Database query failed',
                'message' => $e->getMessage()
            ];
        }
    }
}
```

## Tool Registry

The `ToolRegistry` manages all available tools and provides discovery functionality.

### Registration Methods

```php
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

$toolRegistry = new ToolRegistry();

// Method 1: Register callable
$toolRegistry->register('tool_name', $callable, $schema);

// Method 2: Register tool instance
$toolRegistry->registerTool($toolInstance);
```

### Discovery and Execution

```php
// Check if tool exists
if ($toolRegistry->hasTool('tool_name')) {
    // Execute tool
    $result = $toolRegistry->execute('tool_name', $parameters, $context);
}

// Get all tools for tools/list (protocol version aware)
$toolsList = $toolRegistry->getToolsList('2025-03-26');

// Get tool names only
$names = $toolRegistry->getToolNames();
```

## Tool Annotations

Annotations provide hints about tool behavior to help clients make informed decisions. **Available in protocol versions 2025-03-26 and 2025-06-18.**

### Available Annotations

| Annotation | Type | Default | Description |
|------------|------|---------|-------------|
| `readOnlyHint` | boolean | `true` | Tool only reads data, doesn't modify state |
| `destructiveHint` | boolean | `false` | Tool may cause irreversible changes |
| `idempotentHint` | boolean | `true` | Multiple calls with same params produce same result |
| `openWorldHint` | boolean | `false` | Tool may access external resources |
| `experimental` | boolean | `false` | Tool is experimental/unstable |
| `requiresUserConfirmation` | boolean | `false` | Tool should prompt user before execution |
| `sensitive` | boolean | `false` | Tool handles sensitive data |

### Setting Annotations

```php
// Callable tool with annotations
$toolRegistry->register('delete_file', $handler, [
    'description' => 'Delete a file',
    'inputSchema' => $schema,
    'annotations' => [
        'readOnlyHint' => false,
        'destructiveHint' => true,
        'idempotentHint' => false,
        'requiresUserConfirmation' => true
    ]
]);

// Class-based tool annotations
class FileTool extends AbstractTool
{
    protected function getDefaultAnnotations(): array
    {
        return [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => true,
            'requiresUserConfirmation' => true
        ];
    }
}
```

### Protocol Version Handling

Tool annotations are automatically included/excluded based on protocol version:

```php
// 2024-11-05 response (no annotations)
{
  "tools": [
    {
      "name": "echo",
      "description": "Echo a message",
      "inputSchema": {...}
    }
  ]
}

// 2025-03-26+ response (with annotations)
{
  "tools": [
    {
      "name": "echo",
      "description": "Echo a message",
      "inputSchema": {...},
      "annotations": {
        "readOnlyHint": true,
        "destructiveHint": false,
        "idempotentHint": true,
        "openWorldHint": false
      }
    }
  ]
}
```

## Audio Content Tools

**Available in protocol versions 2025-03-26 and 2025-06-18.**

Tools can return audio content using the `AudioContentHandler`:

```php
use Seolinkmap\Waasup\Content\AudioContentHandler;

$toolRegistry->register('text_to_speech', function($params, $context) {
    $text = $params['text'] ?? '';

    if (empty($text)) {
        return ['error' => 'No text provided'];
    }

    // Generate audio file (replace with your TTS implementation)
    $audioPath = generateSpeechFile($text);

    if (!file_exists($audioPath)) {
        return ['error' => 'Audio generation failed'];
    }

    // Return mixed content with audio
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
    ],
    'annotations' => [
        'readOnlyHint' => false,
        'openWorldHint' => true,
        'experimental' => true
    ]
]);
```

### Audio Content in Class-based Tools

```php
class AudioProcessingTool extends AbstractTool
{
    public function __construct()
    {
        parent::__construct(
            'process_audio',
            'Process audio files',
            [
                'properties' => [
                    'audio_data' => [
                        'type' => 'object',
                        'description' => 'Audio content to process'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['normalize', 'amplify', 'filter'],
                        'description' => 'Processing operation'
                    ]
                ],
                'required' => ['audio_data', 'operation']
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        // Validate audio parameter
        $this->validateAudioParameter($parameters, 'audio_data');

        $audioData = $parameters['audio_data'];
        $operation = $parameters['operation'];

        try {
            // Extract audio to temporary file
            $tempFile = AudioContentHandler::extractToFile($audioData);

            // Process the audio file
            $processedFile = $this->processAudioFile($tempFile, $operation);

            // Return processed audio
            return $this->createAudioResponse(
                $processedFile,
                "Audio processed with operation: {$operation}",
                'processed_audio.mp3'
            );
        } catch (Exception $e) {
            return [
                'error' => 'Audio processing failed',
                'message' => $e->getMessage()
            ];
        }
    }

    private function processAudioFile(string $inputFile, string $operation): string
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'processed_') . '.mp3';

        // Example processing using ffmpeg
        switch ($operation) {
            case 'normalize':
                exec("ffmpeg -i '{$inputFile}' -filter:a loudnorm '{$outputFile}'");
                break;
            case 'amplify':
                exec("ffmpeg -i '{$inputFile}' -filter:a 'volume=2.0' '{$outputFile}'");
                break;
            case 'filter':
                exec("ffmpeg -i '{$inputFile}' -filter:a 'highpass=f=200' '{$outputFile}'");
                break;
        }

        return $outputFile;
    }
}
```

### Supported Audio Formats

```php
// Supported MIME types
$supportedTypes = AudioContentHandler::getSupportedMimeTypes();
// Returns: ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/webm', 'audio/flac', 'audio/aac']

// Check if format is supported
if (AudioContentHandler::isSupportedMimeType('audio/mpeg')) {
    // Process MP3 audio
}
```

## Best Practices

### 1. Input Validation

Always validate and sanitize inputs:

```php
function validateAndExecute($params, $context) {
    // Required parameter check
    if (empty($params['required_field'])) {
        return ['error' => 'Missing required field'];
    }

    // Type validation
    if (!is_string($params['text_field'])) {
        return ['error' => 'Text field must be string'];
    }

    // Range validation
    $limit = $params['limit'] ?? 10;
    if ($limit < 1 || $limit > 100) {
        return ['error' => 'Limit must be between 1 and 100'];
    }

    // Execute safely...
}
```

### 2. Error Handling

Return structured error information:

```php
try {
    // Tool logic here
    return ['result' => $data];
} catch (Exception $e) {
    return [
        'error' => 'Operation failed',
        'error_code' => $e->getCode(),
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ];
}
```

### 3. Context Usage

Leverage context for multi-tenant operations:

```php
function contextAwareTool($params, $context) {
    // Get agency information
    $agencyId = $context['context_data']['id'] ?? null;
    $agencyName = $context['context_data']['name'] ?? 'Unknown';

    // Filter data by agency
    $data = getDataForAgency($agencyId);

    return [
        'agency' => $agencyName,
        'data' => $data,
        'context_id' => $context['context_id'] ?? 'unknown'
    ];
}
```

### 4. Performance Considerations

```php
function performantTool($params, $context) {
    // Use connection pooling for databases
    static $dbPool = null;
    if (!$dbPool) {
        $dbPool = createConnectionPool();
    }

    // Cache expensive operations
    $cacheKey = "tool_result_" . md5(serialize($params));
    if ($cached = getFromCache($cacheKey)) {
        return $cached;
    }

    $result = expensiveOperation($params);
    setCache($cacheKey, $result, 300); // 5 minute cache

    return $result;
}
```

### 5. Security Guidelines

- **Validate all inputs** against expected schemas
- **Sanitize outputs** to prevent injection attacks
- **Use parameterized queries** for database operations
- **Limit resource access** through allowlists
- **Implement rate limiting** for expensive operations
- **Log security events** for audit trails
- **Never execute raw user input** as code or SQL

## Examples

### Complete File Operations Tool

```php
class FileOperationsTool extends AbstractTool
{
    private string $basePath;
    private array $allowedExtensions;

    public function __construct(string $basePath, array $allowedExtensions = [])
    {
        $this->basePath = rtrim($basePath, '/');
        $this->allowedExtensions = $allowedExtensions;

        parent::__construct(
            'file_operations',
            'Perform file operations within allowed directory',
            [
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['read', 'list', 'info'],
                        'description' => 'Operation to perform'
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'File or directory path (relative to base)'
                    ]
                ],
                'required' => ['operation', 'path']
            ],
            [
                'readOnlyHint' => true,
                'openWorldHint' => false,
                'requiresUserConfirmation' => false
            ]
        );
    }

    public function execute(array $parameters, array $context = []): array
    {
        $this->validateParameters($parameters);

        $operation = $parameters['operation'];
        $relativePath = $parameters['path'];

        // Security: Prevent directory traversal
        $safePath = $this->getSafePath($relativePath);
        if (!$safePath) {
            return ['error' => 'Invalid path'];
        }

        switch ($operation) {
            case 'read':
                return $this->readFile($safePath);
            case 'list':
                return $this->listDirectory($safePath);
            case 'info':
                return $this->getFileInfo($safePath);
            default:
                return ['error' => 'Unknown operation'];
        }
    }

    private function getSafePath(string $relativePath): ?string
    {
        // Remove leading slashes and resolve path
        $relativePath = ltrim($relativePath, '/');
        $fullPath = $this->basePath . '/' . $relativePath;
        $realPath = realpath($fullPath);

        // Ensure path is within base directory
        if (!$realPath || !str_starts_with($realPath, $this->basePath)) {
            return null;
        }

        return $realPath;
    }

    private function readFile(string $path): array
    {
        if (!is_file($path)) {
            return ['error' => 'File not found'];
        }

        // Check extension if restrictions exist
        if (!empty($this->allowedExtensions)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->allowedExtensions)) {
                return ['error' => 'File type not allowed'];
            }
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['error' => 'Unable to read file'];
        }

        return [
            'path' => basename($path),
            'size' => strlen($content),
            'content' => $content,
            'mime_type' => mime_content_type($path) ?: 'application/octet-stream'
        ];
    }

    private function listDirectory(string $path): array
    {
        if (!is_dir($path)) {
            return ['error' => 'Directory not found'];
        }

        $items = [];
        $iterator = new DirectoryIterator($path);

        foreach ($iterator as $item) {
            if ($item->isDot()) continue;

            $items[] = [
                'name' => $item->getFilename(),
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
                'modified' => date('c', $item->getMTime())
            ];
        }

        return [
            'path' => basename($path),
            'items' => $items,
            'count' => count($items)
        ];
    }

    private function getFileInfo(string $path): array
    {
        if (!file_exists($path)) {
            return ['error' => 'Path not found'];
        }

        $stat = stat($path);

        return [
            'path' => basename($path),
            'type' => is_dir($path) ? 'directory' : 'file',
            'size' => $stat['size'],
            'permissions' => substr(sprintf('%o', fileperms($path)), -4),
            'created' => date('c', $stat['ctime']),
            'modified' => date('c', $stat['mtime']),
            'accessed' => date('c', $stat['atime'])
        ];
    }
}

// Register with restrictions
$toolRegistry->registerTool(new FileOperationsTool(
    '/var/www/uploads',
    ['txt', 'json', 'csv', 'md']
));
```

### API Integration Tool

```php
$toolRegistry->register('fetch_api_data', function($params, $context) {
    $url = $params['url'] ?? '';
    $method = strtoupper($params['method'] ?? 'GET');
    $headers = $params['headers'] ?? [];
    $timeout = $params['timeout'] ?? 30;

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid URL'];
    }

    // Security: Allow only HTTPS
    if (!str_starts_with($url, 'https://')) {
        return ['error' => 'Only HTTPS URLs allowed'];
    }

    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(function($k, $v) {
                return "{$k}: {$v}";
            }, array_keys($headers), $headers),
            CURLOPT_USERAGENT => 'MCP-Server/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'Request failed'];
        }

        return [
            'status_code' => $httpCode,
            'content_type' => $contentType,
            'response' => $response,
            'is_json' => str_contains($contentType, 'application/json'),
            'timestamp' => date('c')
        ];
    } catch (Exception $e) {
        return [
            'error' => 'Request error',
            'message' => $e->getMessage()
        ];
    }
}, [
    'description' => 'Fetch data from external APIs',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'API endpoint URL (HTTPS only)'],
            'method' => ['type' => 'string', 'enum' => ['GET', 'POST'], 'default' => 'GET'],
            'headers' => ['type' => 'object', 'description' => 'HTTP headers'],
            'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 30]
        ],
        'required' => ['url']
    ],
    'annotations' => [
        'readOnlyHint' => true,
        'openWorldHint' => true,
        'idempotentHint' => false
    ]
]);
```

## Testing Tools

### Unit Testing

```php
use PHPUnit\Framework\TestCase;

class ToolTest extends TestCase
{
    public function testEchoTool()
    {
        $toolRegistry = new ToolRegistry();

        $toolRegistry->register('echo', function($params, $context) {
            return ['message' => $params['message']];
        });

        $result = $toolRegistry->execute('echo', ['message' => 'test'], []);

        $this->assertEquals(['message' => 'test'], $result);
    }

    public function testToolAnnotations()
    {
        $toolRegistry = new ToolRegistry();

        $toolRegistry->register('test_tool', function($params, $context) {
            return ['success' => true];
        }, [
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true
            ]
        ]);

        // Test with 2025-03-26 protocol version
        $toolsList = $toolRegistry->getToolsList('2025-03-26');
        $tool = $toolsList['tools'][0];

        $this->assertArrayHasKey('annotations', $tool);
        $this->assertFalse($tool['annotations']['readOnlyHint']);
        $this->assertTrue($tool['annotations']['destructiveHint']);
    }
}
```

### Integration Testing

```php
// Test with MCP server
$request = [
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => [
        'name' => 'echo',
        'arguments' => ['message' => 'Hello World']
    ],
    'id' => 1
];

$response = $mcpServer->handle($request, $response);
// Assert response contains expected wrapped content
$this->assertStringContains('{"message":"Hello World"}', $response->getBody());
```

## Summary

Tools are the core functionality of your MCP server. Key points to remember:

1. **Tool functions return arrays/objects** (as shown in examples)
2. **Server wraps results as JSON text content** for clients
3. **Clients must parse JSON text** to get structured data
4. **Tool annotations** are available in 2025-03-26+ protocol versions
5. **Audio content** is supported in 2025-03-26+ versions
6. Start with simple callable tools, advance to class-based tools
7. Always prioritize security, validation, and error handling

The response format wrapping ensures MCP protocol compliance while allowing flexible tool result structures across all supported protocol versions.
