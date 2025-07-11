# API Reference

Complete reference for the MCP SaaS Server API implementation, covering all JSON-RPC 2.0 endpoints, message formats, and **asynchronous SSE-based protocol handling**.

## Table of Contents

- [Protocol Overview](#protocol-overview)
- [Asynchronous Response System](#asynchronous-response-system)
- [Message Format](#message-format)
- [Session Management](#session-management)
- [Core Methods](#core-methods)
- [Tools API](#tools-api)
- [Prompts API](#prompts-api)
- [Resources API](#resources-api)
- [Transport Methods](#transport-methods)
- [Error Handling](#error-handling)
- [Examples](#examples)

## Protocol Overview

The MCP SaaS Server implements the Model Context Protocol using JSON-RPC 2.0 over HTTP with **Server-Sent Events (SSE) for asynchronous response delivery**. All communication follows the MCP specification version 2024-11-05 with server-specific extensions.

### Base URL Pattern
```
POST https://your-server.com/mcp/{agencyUuid}[/{sessionId}]
GET  https://your-server.com/mcp/{agencyUuid}/sse (for SSE connection)
```

### Headers
- `Authorization: Bearer <access_token>` - Required for all requests
- `Content-Type: application/json` - Required for POST requests
- `Mcp-Session-Id: <session_id>` - Required after initialization (alternative to URL parameter)

### Protocol Flow
1. **Initialize** - Establish session, get session ID in response header
2. **Connect SSE** - Establish SSE connection for receiving responses
3. **Operate** - Send requests (get `{"status": "queued"}`), receive responses via SSE
4. **Cleanup** - Session expires or explicit termination

## Asynchronous Response System

**CRITICAL**: This server uses an asynchronous response system that differs from standard MCP implementations.

### Request Flow
1. Client sends JSON-RPC request to HTTP endpoint
2. Server validates request and queues response
3. Server immediately returns `{"status": "queued"}` with HTTP 202
4. Actual JSON-RPC response is delivered via SSE connection

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

**Step 3: Actual Response via SSE**
```
event: message
data: {"jsonrpc":"2.0","result":{"tools":[...]},"id":1}
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

### Response Format (via SSE)
```json
{
  "jsonrpc": "2.0",
  "result": { ... },
  "id": 1
}
```

### Error Format (via SSE or HTTP)
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

### Notification Format (via SSE)
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
    "protocolVersion": "2024-11-05",
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
    "protocolVersion": "2024-11-05",
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
      }
    },
    "serverInfo": {
      "name": "MCP SaaS Server",
      "version": "1.0.0"
    }
  },
  "id": 1
}
```

**Note**: The `initialize` method is the ONLY method that returns a direct HTTP response. All other methods return `{"status": "queued"}` and deliver the actual response via SSE.

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
# Actual response via SSE: {"jsonrpc":"2.0","result":{"status":"pong","timestamp":"..."},"id":2}
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Response (via SSE):**
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

**Note**: Resource subscription methods (`resources/subscribe`, `resources/unsubscribe`) are NOT yet implemented in this server.

## Transport Methods

### Server-Sent Events (SSE)
The `SSETransport` class handles real-time response delivery.

**SSE Connection:**
```bash
GET /mcp/550e8400-e29b-41d4-a716-446655440000/sse?access_token=your-token&session_id=sess_123
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

### HTTP Transport
Standard HTTP requests return queued acknowledgment:

```bash
POST /mcp/550e8400-e29b-41d4-a716-446655440000
# Always returns: {"status": "queued"} with HTTP 202
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

### Protocol Errors (via SSE)
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
| -32601 | Method not found | Method does not exist |
| -32602 | Invalid params | Invalid method parameters |
| -32603 | Internal error | Internal JSON-RPC error |
| -32000 | Authentication required | Valid authentication required |
| -32001 | Session required | Valid session required |

## Examples

### Complete Session Flow

**Step 1: Initialize Session**
```bash
curl -X POST https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-access-token" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
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

**Step 2: Establish SSE Connection**
```bash
curl -N https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000/sse \
  -H "Authorization: Bearer your-access-token" \
  -G -d "session_id=sess_1234567890abcdef"
```

**Step 3: Send Request (Separate Terminal)**
```bash
curl -X POST https://server.com/mcp/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer your-access-token" \
  -H "Mcp-Session-Id: sess_1234567890abcdef" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/list",
    "id": 2
  }'

# Returns: {"status": "queued"}
# Actual response appears in SSE connection
```

### JavaScript Client Implementation
```javascript
class MCPClient {
    constructor(baseUrl, accessToken) {
        this.baseUrl = baseUrl;
        this.accessToken = accessToken;
        this.sessionId = null;
        this.eventSource = null;
        this.pendingRequests = new Map();
        this.requestIdCounter = 0;
    }

    async initialize(agencyId) {
        const response = await fetch(`${this.baseUrl}/mcp/${agencyId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                method: 'initialize',
                params: {
                    protocolVersion: '2024-11-05',
                    capabilities: {},
                    clientInfo: { name: 'JS Client', version: '1.0.0' }
                },
                id: 1
            })
        });

        this.sessionId = response.headers.get('Mcp-Session-Id');
        return response.json();
    }

    connectSSE(agencyId) {
        const url = `${this.baseUrl}/mcp/${agencyId}/sse?session_id=${this.sessionId}`;

        this.eventSource = new EventSource(url, {
            headers: {
                'Authorization': `Bearer ${this.accessToken}`
            }
        });

        this.eventSource.addEventListener('message', (event) => {
            const data = JSON.parse(event.data);

            if (data.id && this.pendingRequests.has(data.id)) {
                const { resolve } = this.pendingRequests.get(data.id);
                this.pendingRequests.delete(data.id);
                resolve(data);
            }
        });
    }

    async call(agencyId, method, params = {}) {
        const id = ++this.requestIdCounter;

        const promise = new Promise((resolve, reject) => {
            this.pendingRequests.set(id, { resolve, reject });

            // Timeout after 30 seconds
            setTimeout(() => {
                if (this.pendingRequests.has(id)) {
                    this.pendingRequests.delete(id);
                    reject(new Error('Request timeout'));
                }
            }, 30000);
        });

        await fetch(`${this.baseUrl}/mcp/${agencyId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.accessToken}`,
                'Mcp-Session-Id': this.sessionId,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                method,
                params,
                id
            })
        });

        return promise;
    }
}

// Usage
const client = new MCPClient('https://server.com', 'your-token');
await client.initialize('550e8400-e29b-41d4-a716-446655440000');
client.connectSSE('550e8400-e29b-41d4-a716-446655440000');

const toolsList = await client.call('550e8400-e29b-41d4-a716-446655440000', 'tools/list');
console.log(toolsList.result.tools);
```

This API reference accurately reflects the server's asynchronous, SSE-based response delivery system and the actual implemented methods.
