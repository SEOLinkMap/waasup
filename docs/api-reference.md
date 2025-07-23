# API Reference

Complete reference for the MCP SaaS Server API implementation, covering all JSON-RPC 2.0 endpoints, message formats, and **asynchronous response delivery system**.

## Table of Contents

- [Protocol Overview](#protocol-overview)
- [Asynchronous Response System](#asynchronous-response-system)
- [Message Format](#message-format)
- [Session Management](#session-management)
- [Core Methods](#core-methods)
- [Tools API](#tools-api)
- [Prompts API](#prompts-api)
- [Resources API](#resources-api)
- [Advanced Features](#advanced-features)
- [Transport Methods](#transport-methods)
- [Error Handling](#error-handling)
- [Examples](#examples)

## Protocol Overview

The MCP SaaS Server implements the Model Context Protocol using JSON-RPC 2.0 over HTTP with **Server-Sent Events (SSE) or Streamable HTTP for asynchronous response delivery**. All communication follows MCP specification versions 2024-11-05, 2025-03-26, and 2025-06-18 with automatic feature gating.

### Base URL Pattern
```
POST https://your-server.com/mcp/{agencyUuid}[/{sessionId}]
GET  https://your-server.com/mcp/{agencyUuid} (for streaming connection)
```

### Headers
- `Authorization: Bearer <access_token>` - Required for all requests
- `Content-Type: application/json` - Required for POST requests
- `Mcp-Session-Id: <session_id>` - Required after initialization (alternative to URL parameter)
- `MCP-Protocol-Version: <version>` - Required for 2025-06-18 requests

### Protocol Flow
1. **Initialize** - Establish session, get session ID in response header
2. **Connect Streaming** - Establish SSE (2024-11-05) or Streamable HTTP (2025-03-26+) connection for receiving responses
3. **Operate** - Send requests (get `{"status": "queued"}`), receive responses via streaming
4. **Cleanup** - Session expires or explicit termination

## Asynchronous Response System

**CRITICAL**: This server uses an asynchronous response system that differs from standard MCP implementations.

### Request Flow
1. Client sends JSON-RPC request to HTTP endpoint
2. Server validates request and queues response
3. Server immediately returns `{"status": "queued"}` with HTTP 202
4. Actual JSON-RPC response is delivered via streaming connection

### Example Request/Response Cycle

**Step 1: HTTP Request**
```bash
POST /mcp/550e8400-e29b-41d4-a716-446655440000
Authorization: Bearer your-token
Mcp-Session-Id: sess_123
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "method": "tools/list",
  "id": 1
}
```

**Step 2: Immediate HTTP Response**
```json
HTTP/1.1 202 Accepted
Content-Type: application/json

{"status": "queued"}
```

**Step 3: Actual Response via Streaming**

*SSE Format (2024-11-05):*
```
event: message
data: {"jsonrpc":"2.0","result":{"tools":[...]},"id":1}
```

*Streamable HTTP Format (2025-03-26+):*
```json
{"jsonrpc":"2.0","result":{"tools":[...]},"id":1}
```

## Message Format

All messages follow JSON-RPC 2.0 specification with MCP extensions.

### Request Format
```json
{
  "jsonrpc": "2.0",
  "method": "method_name",
  "params": { ... },
  "id": 1
}
```

### Response Format (via Streaming)
```json
{
  "jsonrpc": "2.0",
  "result": { ... },
  "id": 1
}
```

### Error Format
```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32600,
    "message": "Invalid Request",
    "data": { ... }
  },
  "id": 1
}
```

### Notification Format (via Streaming)
```json
{
  "jsonrpc": "2.0",
  "method": "notifications/message",
  "params": { ... }
}
```

## Session Management

Sessions are created during `initialize` and the session ID is returned in the `Mcp-Session-Id` response header.

### Initialize Request
```json
{
  "jsonrpc": "2.0",
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-03-26",
    "capabilities": {
      "roots": {
        "listChanged": true
      }
    },
    "clientInfo": {
      "name": "Example Client",
      "version": "1.0.0"
    }
  },
  "id": 1
}
```

### Initialize Response (Direct HTTP - Only Exception)
```json
HTTP/1.1 200 OK
Mcp-Session-Id: sess_1234567890abcdef
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "result": {
    "protocolVersion": "2025-03-26",
    "capabilities": {
      "tools": {
        "listChanged": true
      },
      "prompts": {
        "listChanged": true
      },
      "resources": {
        "subscribe": false,
        "listChanged": true
      },
      "completions": true
    },
    "serverInfo": {
      "name": "MCP SaaS Server",
      "version": "1.0.0"
    }
  },
  "id": 1
}
```

**Note**: The `initialize` method is the ONLY method that returns a direct HTTP response. All other methods return `{"status": "queued"}` and deliver the actual response via streaming.

### Session Validation
All non-initialize requests require valid session:

```bash
POST /mcp/550e8400-e29b-41d4-a716-446655440000
Authorization: Bearer your-access-token
Mcp-Session-Id: sess_1234567890abcdef
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "method": "ping",
  "id": 2
}

# Response: {"status": "queued"}
# Actual response via streaming: {"jsonrpc":"2.0","result":{"status":"pong","timestamp":"..."},"id":2}
```

## Core Methods

### ping
Test server connectivity and session validity.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "ping",
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "status": "pong",
    "timestamp": "2024-01-15T10:30:00Z"
  },
  "id": 1
}
```

## Tools API

### tools/list
List all available tools for the current context.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/list",
  "id": 1
}
```

**Response (via Streaming):**

*2024-11-05 Format:*
```json
{
  "jsonrpc": "2.0",
  "result": {
    "tools": [
      {
        "name": "echo",
        "description": "Echo a message back",
        "inputSchema": {
          "type": "object",
          "properties": {
            "message": {
              "type": "string",
              "description": "Message to echo"
            }
          },
          "required": ["message"]
        }
      }
    ]
  },
  "id": 1
}
```

*2025-03-26+ Format (with annotations):*
```json
{
  "jsonrpc": "2.0",
  "result": {
    "tools": [
      {
        "name": "echo",
        "description": "Echo a message back",
        "inputSchema": {
          "type": "object",
          "properties": {
            "message": {
              "type": "string",
              "description": "Message to echo"
            }
          },
          "required": ["message"]
        },
        "annotations": {
          "readOnlyHint": true,
          "destructiveHint": false,
          "idempotentHint": true,
          "openWorldHint": false
        }
      }
    ]
  },
  "id": 1
}
```

### tools/call
Execute a specific tool with parameters.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "echo",
    "arguments": {
      "message": "Hello MCP!"
    }
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"message\":\"Hello MCP!\",\"received_params\":{\"message\":\"Hello MCP!\"},\"context_available\":true}"
      }
    ]
  },
  "id": 1
}
```

**Note**: Tool results are JSON-encoded as text content, not structured objects.

## Prompts API

### prompts/list
List all available prompts for the current context.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "prompts/list",
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "prompts": [
      {
        "name": "greeting",
        "description": "Generate a friendly greeting prompt",
        "arguments": [
          {
            "name": "name",
            "description": "Name of the person to greet",
            "required": false
          }
        ]
      }
    ]
  },
  "id": 1
}
```

### prompts/get
Get a specific prompt with arguments.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "prompts/get",
  "params": {
    "name": "greeting",
    "arguments": {
      "name": "Alice"
    }
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "description": "A friendly greeting prompt",
    "messages": [
      {
        "role": "user",
        "content": [
          {
            "type": "text",
            "text": "Please greet Alice in a friendly way."
          }
        ]
      }
    ]
  },
  "id": 1
}
```

## Resources API

### resources/list
List all available resources for the current context.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "resources/list",
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "resources": [
      {
        "uri": "server://status",
        "name": "Server Status",
        "description": "Current server status and health information",
        "mimeType": "application/json"
      }
    ]
  },
  "id": 1
}
```

### resources/read
Read the contents of a specific resource.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "resources/read",
  "params": {
    "uri": "server://status"
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "contents": [
      {
        "uri": "server://status",
        "mimeType": "application/json",
        "text": "{\"status\":\"healthy\",\"timestamp\":\"2024-01-15T10:30:00Z\",\"uptime\":12345}"
      }
    ]
  },
  "id": 1
}
```

### resources/templates/list
List available resource templates.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "resources/templates/list",
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "resourceTemplates": [
      {
        "uriTemplate": "file://{path}",
        "name": "File Resource",
        "description": "Read file contents from the server",
        "mimeType": "text/plain"
      }
    ]
  },
  "id": 1
}
```

## Advanced Features

### completions/complete (2025-03-26+)
Get completions for tool or prompt references.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "completions/complete",
  "params": {
    "ref": {
      "type": "ref/tool",
      "name": "echo"
    },
    "argument": "mes"
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "completions": [
      {
        "value": "message",
        "description": "Message parameter"
      }
    ]
  },
  "id": 1
}
```

### elicitation/create (2025-06-18)
Request structured user input.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "elicitation/create",
  "params": {
    "message": "Please provide your contact information",
    "requestedSchema": {
      "type": "object",
      "properties": {
        "name": {"type": "string"},
        "email": {"type": "string", "format": "email"}
      },
      "required": ["name", "email"]
    }
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "type": "elicitation",
    "prompt": "Please provide your contact information",
    "requestedSchema": {
      "type": "object",
      "properties": {
        "name": {"type": "string"},
        "email": {"type": "string", "format": "email"}
      },
      "required": ["name", "email"]
    },
    "requestId": 1
  },
  "id": 1
}
```

### sampling/createMessage
Request LLM sampling from connected client.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "method": "sampling/createMessage",
  "params": {
    "messages": [
      {
        "role": "user",
        "content": [
          {
            "type": "text",
            "text": "What is the capital of France?"
          }
        ]
      }
    ],
    "includeContext": "none",
    "temperature": 0.7,
    "maxTokens": 100
  },
  "id": 1
}
```

**Response (via Streaming):**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "received": true
  },
  "id": 1
}
```

### Audio Content (2025-03-26+)

Tools can return audio content:

**Tool Response with Audio:**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Generated audio file:"
      },
      {
        "type": "audio",
        "data": "base64-encoded-audio-data",
        "mimeType": "audio/mpeg",
        "name": "speech.mp3"
      }
    ]
  },
  "id": 1
}
```

### Progress Notifications

Server can send progress updates:

*2024-11-05 Format:*
```json
{
  "jsonrpc": "2.0",
  "method": "notifications/progress",
  "params": {
    "progress": 50,
    "total": 100
  }
}
```

*2025-03-26+ Format (with message):*
```json
{
  "jsonrpc": "2.0",
  "method": "notifications/progress",
  "params": {
    "progress": 50,
    "total": 100,
    "message": "Processing data..."
  }
}
```

### JSON-RPC Batching (2025-03-26 only)

**Batch Request:**
```json
[
  {
    "jsonrpc": "2.0",
    "method": "tools/list",
    "id": 1
  },
  {
    "jsonrpc": "2.0",
    "method": "prompts/list",
    "id": 2
  }
]
```

**Batch Response:**
```json
[
  {
    "jsonrpc": "2.0",
    "result": {"tools": [...]},
    "id": 1
  },
  {
    "jsonrpc": "2.0",
    "result": {"prompts": [...]},
    "id": 2
  }
]
```

**Note**: Batching is NOT supported in 2025-06-18.

## Transport Methods

### Server-Sent Events (SSE) - 2024-11-05, Fallback
The `SSETransport` class handles real-time response delivery.

**SSE Connection:**
```bash
GET /mcp/550e8400-e29b-41d4-a716-446655440000?session_id=sess_123
Authorization: Bearer your-token
Accept: text/event-stream
Cache-Control: no-cache
```

**SSE Events:**
```
event: endpoint
data: https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000/sess_123

event: message
data: {"jsonrpc":"2.0","result":{"tools":[...]},"id":1}

: keepalive

event: message
data: {"jsonrpc":"2.0","result":{"status":"pong"},"id":2}
```

### Streamable HTTP - 2025-03-26+
For newer protocol versions, uses chunked HTTP streaming.

**Connection:**
```bash
GET /mcp/550e8400-e29b-41d4-a716-446655440000?session_id=sess_123
Authorization: Bearer your-token
MCP-Protocol-Version: 2025-03-26
```

**Response Stream:**
```json
{"jsonrpc":"2.0","method":"notifications/connection","params":{"status":"connected","sessionId":"sess_123"}}
{"jsonrpc":"2.0","result":{"tools":[...]},"id":1}
{"jsonrpc":"2.0","method":"notifications/ping","params":{"timestamp":"2024-01-15T10:30:00Z"}}
```

## Error Handling

### Authentication Errors (HTTP Response)
When authentication fails, the server returns OAuth discovery information:

```json
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "jsonrpc": "2.0",
  "error": {
    "code": -32000,
    "message": "Authentication required",
    "data": {
      "oauth": {
        "authorization_endpoint": "https://server.com/oauth/authorize",
        "token_endpoint": "https://server.com/oauth/token",
        "registration_endpoint": "https://server.com/oauth/register"
      }
    }
  },
  "id": null
}
```

### Protocol Errors (via Streaming)
```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32601,
    "message": "Method not found"
  },
  "id": 1
}
```

### Error Code Reference
| Code | Name | Description |
|------|------|-------------|
| -32700 | Parse error | Invalid JSON received |
| -32600 | Invalid Request | JSON is not valid Request object |
| -32601 | Method not found | Method does not exist or not supported in protocol version |
| -32602 | Invalid params | Invalid method parameters |
| -32603 | Internal error | Internal JSON-RPC error |
| -32000 | Authentication required | Valid authentication required |
| -32001 | Session required | Valid session required |
| -32002 | Method not allowed | HTTP method not supported |

## Examples

### Complete Session Flow

**Step 1: Initialize Session**
```bash
curl -X POST https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-access-token" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-03-26" \
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

# Response includes: Mcp-Session-Id header
```

**Step 2: Establish Streaming Connection**
```bash
curl -N https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-access-token" \
  -H "MCP-Protocol-Version: 2025-03-26" \
  -G -d "session_id=sess_1234567890abcdef"
```

**Step 3: Send Request (Separate Terminal)**
```bash
curl -X POST https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-access-token" \
  -H "Mcp-Session-Id: sess_1234567890abcdef" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-03-26" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "id": 2
  }'

# Returns: {"status": "queued"}
# Actual response appears in streaming connection
```

### JavaScript Client Implementation
```javascript
class MCPClient {
    constructor(baseUrl, accessToken, protocolVersion = '2025-03-26') {
        this.baseUrl = baseUrl;
        this.accessToken = accessToken;
        this.protocolVersion = protocolVersion;
        this.sessionId = null;
        this.connection = null;
        this.pendingRequests = new Map();
        this.requestIdCounter = 0;
    }

    async initialize(agencyId) {
        const headers = {
            'Authorization': `Bearer ${this.accessToken}`,
            'Content-Type': 'application/json'
        };

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
                    throw new Error('Authentication required - OAuth endpoints: ' +
                                   JSON.stringify(error.error.data.oauth));
                }
            }
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        this.sessionId = response.headers.get('Mcp-Session-Id');
        return response.json();
    }

    connectStreaming(agencyId) {
        const url = `${this.baseUrl}/mcp/${agencyId}?session_id=${this.sessionId}`;

        if (this.shouldUseStreamableHTTP()) {
            this.connectStreamableHTTP(url);
        } else {
            this.connectSSE(url);
        }
    }

    shouldUseStreamableHTTP() {
        return ['2025-03-26', '2025-06-18'].includes(this.protocolVersion);
    }

    connectSSE(url) {
        const headers = {
            'Authorization': `Bearer ${this.accessToken}`
        };

        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

        this.connection = new EventSource(url, { headers });

        this.connection.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);
            this.handleMessage(data);
        });

        this.connection.addEventListener('error', (event) => {
            console.error('SSE connection error:', event);
        });
    }

    connectStreamableHTTP(url) {
        const headers = {
            'Authorization': `Bearer ${this.accessToken}`
        };

        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

        fetch(url, { headers })
            .then(response => {
                const reader = response.body.getReader();
                this.processStreamableHTTP(reader);
            })
            .catch(error => {
                console.error('Streamable HTTP connection error:', error);
            });
    }

    async processStreamableHTTP(reader) {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const text = new TextDecoder().decode(value);
            const lines = text.split('\n');

            for (const line of lines) {
                if (line.trim()) {
                    try {
                        const data = JSON.parse(line);
                        this.handleMessage(data);
                    } catch (e) {
                        // Ignore parse errors for partial chunks
                    }
                }
            }
        }
    }

    handleMessage(data) {
        if (data.id && this.pendingRequests.has(data.id)) {
            const { resolve } = this.pendingRequests.get(data.id);
            this.pendingRequests.delete(data.id);
            resolve(data);
        } else if (data.method === 'notifications/progress') {
            console.log('Progress:', data.params);
        } else if (data.method === 'notifications/connection') {
            console.log('Connection status:', data.params.status);
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

        if (this.protocolVersion === '2025-06-18') {
            headers['MCP-Protocol-Version'] = this.protocolVersion;
        }

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

        return promise;
    }

    disconnect() {
        if (this.connection) {
            if (this.connection.close) {
                this.connection.close();
            }
            this.connection = null;
        }
    }
}

// Usage
const client = new MCPClient('https://server.com', 'your-token', '2025-03-26');
await client.initialize('550e8400-e29b-41d4-a716-446655440000');
client.connectStreaming('550e8400-e29b-41d4-a716-446655440000');

const toolsList = await client.call('550e8400-e29b-41d4-a716-446655440000', 'tools/list');
console.log(toolsList.result.tools);
```
