# Building Tools

[Installation](getting-started.md) | [Configuration](configuration.md) | [Authentication](authentication.md) | **Building Tools** | [API Reference](api-reference.md)

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

---

## Overview

WaaSuP transforms your application's business logic into AI-accessible capabilities through **tools**, **prompts**, and **resources**. This is where you add the intelligence and functionality that makes your MCP server valuable to AI assistants and users.

Think of this as the "business logic layer" - while WaaSuP handles the protocol complexity, authentication, and communication, you focus on what your application *does*. The AI provides the conversational interface, but your code performs the actual work through mathematical algorithms, database queries, API calls, and business rules.

## Tools: Interactive Functions

Tools are interactive functions that AI assistants can call to perform actions or retrieve data. They're the primary way AI interacts with your application's functionality.

### Basic Tool Registration

```php
$toolRegistry->register(
    'get_customer_data',
    function ($params, $context) {
        // Your business logic here
        $customerId = $params['customer_id'];
        $agencyData = $context['context_data'];

        // Access your database, APIs, etc.
        $customer = getCustomerFromDatabase($customerId, $agencyData['id']);

        return [
            'customer' => $customer,
            'retrieved_at' => date('c')
        ];
    },
    [
        'description' => 'Retrieve customer information by ID',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Unique customer identifier'
                ]
            ],
            'required' => ['customer_id']
        ],
        'annotations' => [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false
        ]
    ]
);
```

### Tool Function Structure

Your tool functions receive two parameters:

**`$params`** - User/AI-provided parameters based on your input schema:
```php
function ($params, $context) {
    $searchQuery = $params['query'] ?? '';
    $limit = $params['limit'] ?? 10;
    $filters = $params['filters'] ?? [];
}
```

**`$context`** - Authentication and system context:
```php
function ($params, $context) {
    // Access authenticated user/agency data
    $agencyData = $context['context_data'];
    $agencyId = $agencyData['id'];
    $agencyName = $agencyData['name'];

    // Access token information
    $tokenData = $context['token_data'];
    $userId = $tokenData['user_id'];
    $scopes = explode(' ', $tokenData['scope']);

    // Other context
    $sessionId = $context['sessionid'];
    $baseUrl = $context['base_url'];
    $isAuthless = $context['authless'] ?? false;
}
```

### Tool Return Values

A tool can return in one of three shapes, and the server treats each differently.

**A plain array** is serialized to JSON and delivered as a single text block. This is the
simplest form and every example above uses it:

```php
return ['customer' => $customer, 'retrieved_at' => date('c')];
```

**Content blocks** are delivered to the client exactly as written, which is how you return
anything that is not text. Use this for images, audio, resource links and embedded
resources:

```php
return [
    'content' => [
        ['type' => 'text', 'text' => 'Chart for Q3:'],
        ['type' => 'image', 'data' => base64_encode($png), 'mimeType' => 'image/png'],
        ['type' => 'resource_link', 'uri' => 'file:///reports/q3.pdf', 'name' => 'q3.pdf']
    ]
];
```

**Structured content** pairs machine-readable data with its text rendering. Declare an
`outputSchema` and the server checks what you return against it, refusing the call with
`-32603` if the structured content is missing or does not match:

```php
$toolRegistry->register(
    'get_weather',
    function ($params, $context) {
        $data = ['temperature' => 22.5, 'conditions' => 'Partly cloudy'];

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data)]],
            'structuredContent' => $data
        ];
    },
    [
        'description' => 'Current weather for a location',
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'temperature' => ['type' => 'number'],
                'conditions' => ['type' => 'string']
            ],
            'required' => ['temperature', 'conditions']
        ]
    ]
);
```

The check covers the declared type, required properties and each declared property's
type. Nested subschemas and composition keywords are not checked.

### Reporting Tool Failures

Failures are reported at one of two levels:

- **Tool execution errors** are returned as a result with `'isError' => true`. Use this
  for API failures, validation problems and business rules.
- **Protocol errors** are raised by the server rather than the tool: an unknown tool
  name, or a malformed request.

```php
return [
    'content' => [
        [
            'type' => 'text',
            'text' => 'Invalid departure date: must be in the future. Today is ' . date('m/d/Y') . '.'
        ]
    ],
    'isError' => true
];
```

Throwing an exception from your tool produces the same result: the server catches it and
returns an `isError` result carrying the exception message.

### Advanced Tool Patterns

**Context-Aware Data Access**:
```php
$toolRegistry->register(
    'list_projects',
    function ($params, $context) use ($database) {
        $agencyData = $context['context_data'];

        if (!$agencyData) {
            return [
                'error' => 'Authentication required to access projects',
                'help' => 'This tool requires user authentication. Please ensure you are logged in.'
            ];
        }

        // Automatically filter by authenticated agency
        $projects = $database->getProjectsForAgency($agencyData['id']);

        return [
            'projects' => $projects,
            'agency' => $agencyData['name'],
            'total_count' => count($projects)
        ];
    },
    [
        'description' => 'List all projects for the authenticated organization',
        'inputSchema' => ['type' => 'object']
    ]
);
```

**State Management Tools**:
```php
$toolRegistry->register(
    'switch_project',
    function ($params, $context) use ($database) {
        $projectId = $params['project_id'];
        $tokenData = $context['token_data'];
        $accessToken = $tokenData['access_token'];

        // Update token state to remember selected project
        $database->updateTokenProjectContext($accessToken, $projectId);

        return [
            'success' => true,
            'active_project_id' => $projectId,
            'message' => 'Project context updated. Subsequent tools will use this project.'
        ];
    },
    [
        'description' => 'Switch to a different project context',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer', 'description' => 'Project to switch to']
            ],
            'required' => ['project_id']
        ]
    ]
);
```

**Complex Analysis Tools**:
```php
$toolRegistry->register(
    'analyze_performance_metrics',
    function ($params, $context) use ($analyticsEngine) {
        $dataSet = $params['data_set'];
        $timeRange = $params['time_range'] ?? '30d';

        try {
            // Your mathematical algorithms and business logic
            $results = $analyticsEngine->calculateMetrics($dataSet, $timeRange);
            $recommendations = $analyticsEngine->generateRecommendations($results);

            return [
                'analysis' => $results,
                'recommendations' => $recommendations,
                'analyzed_at' => date('c'),
                'methodology' => 'Proprietary algorithm v2.1'
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'error' => 'Invalid data set specified',
                'help' => 'Use the list_available_datasets tool to see valid options',
                'provided_value' => $dataSet
            ];
        }
    },
    [
        'description' => 'Run performance analysis on specified data set',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'data_set' => [
                    'type' => 'string',
                    'description' => 'Data set identifier to analyze'
                ],
                'time_range' => [
                    'type' => 'string',
                    'description' => 'Analysis time range (7d, 30d, 90d)'
                ]
            ],
            'required' => ['data_set']
        ],
        'annotations' => [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false
        ]
    ]
);
```

### Tool Annotations

Annotations help AI assistants understand tool behavior and make better decisions:

```php
'annotations' => [
    'readOnlyHint' => false,         // Tool modifies data
    'destructiveHint' => true,       // Tool could delete/damage data
    'idempotentHint' => false,       // Repeated calls have different effects
    'openWorldHint' => true          // Tool accepts flexible parameters
]
```

Annotations are sent only when you declare them. A tool that declares none is advertised
with no hints.

`readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint` are the hints the
specification defines. Any other key you add travels as extra metadata and clients are
free to ignore it.

## AI-Helpful Error Messaging

Design error messages that guide AI assistants to make better next moves, rather than generic error responses:

### Contextual Error Messages

**Instead of generic "not found":**
```php
// Poor: Generic error, and not flagged as a failure
return ['error' => 'Customer not found'];

// Better: helpful guidance, flagged as a failure
return [
    'content' => [
        [
            'type' => 'text',
            'text' => 'No customer found with ID ' . $customerId . '. '
                . 'Use search_customers to find customers by name or email, or '
                . 'list_customers to see available IDs. Customer IDs are numeric.'
        ]
    ],
    'isError' => true
];
```

Without `isError` the result is reported as a success and the error text is treated as
the answer.

**Guide AI through required workflows:**
```php
$toolRegistry->register(
    'get_detailed_report',
    function ($params, $context) use ($database) {
        $projectId = getCurrentProject($context);

        if (!$projectId) {
            return [
                'error' => 'No project selected for detailed reporting',
                'help' => 'Use switch_project tool first to select a project context',
                'next_step' => 'Call list_projects to see available options, then switch_project with your chosen project_id'
            ];
        }

        // Continue with report generation...
    }
);
```

**Provide usage context:**
```php
$toolRegistry->register(
    'advanced_analytics',
    function ($params, $context) use ($database) {
        $agencyData = $context['context_data'];

        if (!hasFeatureAccess($agencyData['id'], 'advanced_analytics')) {
            return [
                'error' => 'Advanced analytics not available for current plan',
                'help' => 'This feature requires a premium subscription',
                'alternative' => 'Use basic_analytics tool for standard reporting',
                'upgrade_info' => 'Contact support or visit billing settings to upgrade'
            ];
        }

        // Continue with advanced analytics...
    }
);
```

### Progressive Error Assistance

Help AI assistants understand parameter requirements:

```php
$toolRegistry->register(
    'search_records',
    function ($params, $context) {
        $query = $params['query'] ?? '';
        $filters = $params['filters'] ?? [];

        if (empty($query) && empty($filters)) {
            return [
                'error' => 'Search requires either a query string or filters',
                'help' => 'Provide a search query for text-based search, or use filters for structured search',
                'examples' => [
                    'text_search' => 'Set query parameter to search names, descriptions, etc.',
                    'filtered_search' => 'Set filters like {"category": "active", "created_after": "2024-01-01"}'
                ]
            ];
        }

        if (strlen($query) < 3 && !empty($query)) {
            return [
                'error' => 'Search query too short',
                'help' => 'Query must be at least 3 characters for effective searching',
                'suggestion' => 'Try a more specific search term or use filters instead'
            ];
        }

        // Continue with search...
    }
);
```

## Prompts: AI Conversation Templates

Prompts provide structured conversation templates that help AI assistants interact more effectively with your domain.

### Basic Prompt Registration

```php
$promptRegistry->register(
    'analyze_business_metrics',
    function ($arguments, $context) {
        $metricType = $arguments['metric_type'] ?? 'general';
        $timeFrame = $arguments['time_frame'] ?? 'current_month';

        return [
            'description' => 'Business metrics analysis conversation starter',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'You are a business analyst assistant. Use available tools to gather data and provide insights based on mathematical analysis, not speculation.'
                        ]
                    ]
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Please analyze the {$metricType} metrics for {$timeFrame}. Use the get_performance_data and analyze_trends tools to gather current data."
                        ]
                    ]
                ]
            ]
        ];
    },
    [
        'description' => 'Generate business metrics analysis conversation with tool usage guidance',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'metric_type' => [
                    'type' => 'string',
                    'description' => 'Type of metrics to analyze'
                ],
                'time_frame' => [
                    'type' => 'string',
                    'description' => 'Time period for analysis'
                ]
            ]
        ]
    ]
);
```

### Dynamic Context-Aware Prompts

```php
$promptRegistry->register(
    'project_status_report',
    function ($arguments, $context) {
        $agencyData = $context['context_data'];
        $reportType = $arguments['report_type'] ?? 'summary';

        $systemMessage = "You are a project manager for {$agencyData['name']}. ";
        $systemMessage .= "Create detailed status reports using available project tools. ";
        $systemMessage .= "All analysis should be based on actual data from tools, not assumptions.";

        return [
            'description' => 'Project status reporting assistant',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $systemMessage
                        ]
                    ]
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Generate a {$reportType} status report. Use list_projects, get_project_metrics, and get_performance_data tools to gather current information."
                        ]
                    ]
                ]
            ]
        ];
    }
);
```

## Resources: Information Access

Resources provide read-only access to information and content that AI assistants can reference.

### Basic Resource Registration

```php
$resourceRegistry->register(
    'company://policies/data-handling',
    function ($uri, $context) use ($database) {
        $agencyData = $context['context_data'];
        $policy = $database->getDataHandlingPolicy($agencyData['id']);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/markdown',
                    'text' => $policy['content']
                ]
            ]
        ];
    },
    [
        'name' => 'Data Handling Policy',
        'description' => 'Company data handling and privacy policy',
        'mimeType' => 'text/markdown'
    ]
);
```

### Resource Templates for Dynamic Content

```php
$resourceRegistry->registerTemplate(
    'project://{project_id}/documentation/{doc_type}',
    function ($uri, $context) use ($database) {
        // Extract parameters from URI
        if (preg_match('#project://(\d+)/documentation/(\w+)#', $uri, $matches)) {
            $projectId = $matches[1];
            $docType = $matches[2];

            $documentation = $database->getProjectDocumentation($projectId, $docType);

            return [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'text/markdown',
                        'text' => $documentation['content']
                    ]
                ]
            ];
        }

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/plain',
                    'text' => 'Invalid resource URI format. Use: project://{project_id}/documentation/{doc_type}'
                ]
            ]
        ];
    },
    [
        'name' => 'Project Documentation',
        'description' => 'Dynamic project documentation access',
        'mimeType' => 'text/markdown'
    ]
);
```

## Protocol Version Considerations

Different MCP protocol versions support different features. WaaSuP automatically handles version negotiation and feature gating.

### Version-Specific Features

**Tool Annotations** (2025-03-26+):
```php
// Annotations automatically included for newer protocol versions
'annotations' => [
    'readOnlyHint' => true,
    'destructiveHint' => false
]
```

**Audio Content** (2025-03-26+):
```php
$toolRegistry->register(
    'generate_audio_report',
    function ($params, $context) {
        // Audio content creation
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Generated audio report:'
                ],
                [
                    'type' => 'audio',
                    'mimeType' => 'audio/mpeg',
                    'data' => base64_encode($audioData)
                ]
            ]
        ];
    }
);
```

**Display Icons and Titles** (icons 2025-11-25, titles 2025-06-18):
```php
$toolRegistry->register(
    'get_weather',
    function ($params, $context) { /* ... */ },
    [
        'title' => 'Weather Lookup',
        'description' => 'Current weather for a location',
        'icons' => [
            [
                'src' => 'https://example.com/weather.svg',
                'mimeType' => 'image/svg+xml',
                'sizes' => ['any']
            ]
        ]
    ]
);
```

Both are withheld from clients on protocol versions that predate them. Prompts, resources
and resource templates accept the same `title` and `icons` keys. Class-based tools,
prompts and resources supply them through `getTitle()` and `getIcons()`, which
`AbstractTool`, `AbstractPrompt` and `AbstractResource` implement from optional
constructor arguments.

**Task Execution** (2025-11-25):
```php
$toolRegistry->register(
    'generate_report',
    function ($params, $context) { /* ... */ },
    [
        'description' => 'Build a report',
        'execution' => ['taskSupport' => 'optional']
    ]
);
```

A tool only accepts task augmentation when it declares `execution.taskSupport`. `optional`
lets a client call it either way, `required` means a plain call is refused. Without the
key, a client that sends a `task` parameter is refused with `-32601`.

The client calls the tool with a `task` parameter and receives a task id instead of the
result, then retrieves the result with `tasks/result`:

```json
{"method": "tools/call", "params": {"name": "generate_report", "arguments": {}, "task": {"ttl": 60000}}}
{"result": {"task": {"taskId": "...", "status": "completed", "ttl": 60000, "pollInterval": 1000}}}
{"method": "tasks/result", "params": {"taskId": "..."}}
```

Your tool code is unchanged. The call runs during the originating request and the task is
terminal when the task id is returned. `tasks/get`, `tasks/list` and `tasks/cancel` operate
on tasks held against the session.

**Structured Outputs** (2025-06-18):
```php
$toolRegistry->register(
    'get_structured_metrics',
    function ($params, $context) {
        $data = ['metrics' => [...], 'recommendations' => [...]];

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($data, JSON_PRETTY_PRINT)
                ]
            ],
            'structuredContent' => $data,  // Structured output for 2025-06-18
            '_meta' => ['structured' => true]
        ];
    }
);
```

## Best Practices

### Security and Context Validation

Always validate context and implement proper access controls:

```php
$toolRegistry->register(
    'sensitive_operation',
    function ($params, $context) {
        // Validate authentication
        $agencyData = $context['context_data'] ?? null;
        if (!$agencyData) {
            return [
                'error' => 'Authentication required for this operation',
                'help' => 'This tool requires user authentication. Please ensure you are logged in.'
            ];
        }

        // Validate permissions
        $tokenData = $context['token_data'] ?? null;
        if (!$tokenData || !str_contains($tokenData['scope'], 'admin')) {
            return [
                'error' => 'Administrative privileges required',
                'help' => 'This operation requires admin-level access. Contact your administrator.',
                'required_scope' => 'admin'
            ];
        }

        // Validate agency access to resource
        if (!hasAgencyAccess($agencyData['id'], $params['resource_id'])) {
            return [
                'error' => 'Access denied to this resource',
                'help' => 'The resource belongs to a different organization or does not exist'
            ];
        }

        // Proceed with operation...
    }
);
```

### Safe Error Handling

Never expose internal errors or system details. Log internally, return safe messages:

```php
$toolRegistry->register(
    'process_data',
    function ($params, $context) {
        try {
            // Validation
            if (empty($params['data_source'])) {
                return [
                    'error' => 'Missing required parameter: data_source',
                    'help' => 'Use list_data_sources tool to see available options'
                ];
            }

            // Processing
            $result = processData($params['data_source']);

            return ['result' => $result];

        } catch (\InvalidArgumentException $e) {
            // Safe to return validation errors
            return [
                'error' => 'Invalid data source specified',
                'help' => 'Use list_data_sources to see valid options',
                'provided_value' => $params['data_source']
            ];
        } catch (\Exception $e) {
            // Log internal errors privately, return generic safe message
            error_log("Tool error in process_data: " . $e->getMessage());
            return [
                'error' => 'Data processing temporarily unavailable',
                'help' => 'Please try again in a few moments or contact support if the issue persists'
            ];
        }
    }
);
```

### Performance Optimization

Implement pagination and limits for large datasets:

```php
$toolRegistry->register(
    'list_large_dataset',
    function ($params, $context) use ($database) {
        $limit = min($params['limit'] ?? 50, 500);  // Cap at 500
        $offset = max($params['offset'] ?? 0, 0);

        $results = $database->getResults($limit, $offset);
        $totalCount = $database->getResultsCount();

        return [
            'results' => $results,
            'count' => count($results),
            'total_count' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($results)) < $totalCount,
            'next_offset' => ($offset + count($results)) < $totalCount ? $offset + $limit : null
        ];
    },
    [
        'description' => 'List large dataset with pagination',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default: 50, max: 500)',
                    'minimum' => 1,
                    'maximum' => 500
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Results to skip (default: 0)',
                    'minimum' => 0
                ]
            ]
        ]
    ]
);
```

## Tool Discovery and Organization

### Organize Tools by Function

Group related tools logically:

```php
// Project management tools
$toolRegistry->register('list_projects', ...);
$toolRegistry->register('create_project', ...);
$toolRegistry->register('switch_project', ...);

// Analytics tools
$toolRegistry->register('get_performance_data', ...);
$toolRegistry->register('analyze_trends', ...);
$toolRegistry->register('generate_report', ...);

// Customer management tools
$toolRegistry->register('search_customers', ...);
$toolRegistry->register('get_customer_details', ...);
$toolRegistry->register('update_customer_status', ...);
```

### Provide Context in Descriptions

Make tool purposes clear to AI assistants:

```php
'description' => 'Switch to a different project by ID or name. USER MUST CHOOSE - AI should not automatically select projects. Use list_projects first to show options.'
```

### Create Helper and Info Tools

Provide tools that help users understand your system:

```php
$toolRegistry->register(
    'get_platform_info',
    function ($params, $context) use ($toolRegistry) {
        return [
            'platform' => 'Your Platform Name',
            'version' => '1.0.0',
            'capabilities' => [
                'project_management' => true,
                'analytics_integration' => true,
                'customer_management' => true
            ],
            'tools_available' => count($toolRegistry->getToolNames()),
            'authentication_status' => !empty($context['context_data']) ? 'authenticated' : 'anonymous'
        ];
    },
    [
        'description' => 'Get platform capabilities and current session information'
    ]
);
```

## Integration Examples

### Database Integration

```php
$toolRegistry->register(
    'search_customers',
    function ($params, $context) use ($pdo) {
        $agencyData = $context['context_data'];
        $query = $params['query'];

        if (strlen($query) < 2) {
            return [
                'error' => 'Search query too short',
                'help' => 'Please provide at least 2 characters for customer search',
                'suggestion' => 'Try searching by partial name, email, or customer ID'
            ];
        }

        $sql = "SELECT id, name, email, status FROM customers
                WHERE agency_id = :agency_id
                AND (name LIKE :query OR email LIKE :query)
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':agency_id' => $agencyData['id'],
            ':query' => "%{$query}%"
        ]);

        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($customers)) {
            return [
                'customers' => [],
                'message' => "No customers found matching '{$query}'",
                'help' => 'Try a different search term or use list_customers to see all customers'
            ];
        }

        return [
            'customers' => $customers,
            'search_query' => $query,
            'total_found' => count($customers)
        ];
    }
);
```

### API Integration

```php
$toolRegistry->register(
    'get_external_data',
    function ($params, $context) use ($httpClient) {
        $endpoint = $params['endpoint'];
        $agencyData = $context['context_data'];

        try {
            // Use agency-specific API credentials
            $apiKey = getAgencyApiKey($agencyData['id'], 'external_service');

            if (!$apiKey) {
                return [
                    'error' => 'External service not configured',
                    'help' => 'API integration requires setup in account settings',
                    'next_step' => 'Contact administrator to configure external service access'
                ];
            }

            $response = $httpClient->get($endpoint, [
                'headers' => ['Authorization' => "Bearer {$apiKey}"]
            ]);

            return [
                'data' => $response->json(),
                'retrieved_at' => date('c'),
                'source' => 'external_api'
            ];

        } catch (\Exception $e) {
            // Log the actual error internally
            error_log("External API error: " . $e->getMessage());

            return [
                'error' => 'External service temporarily unavailable',
                'help' => 'The external data source is currently unreachable',
                'retry_suggestion' => 'Please try again in a few minutes'
            ];
        }
    }
);
```

## Getting Started

1. **Start Simple**: Begin with basic read-only tools that expose your existing data
2. **Add Context**: Implement proper authentication and multi-tenant access controls
3. **Build Complexity**: Add state management, external integrations, and advanced features
4. **Design for AI**: Create error messages that guide AI assistants to better next steps
5. **Test Thoroughly**: Use the MCP client to test tool behavior and error handling
6. **Get Help**: Connect to `https://seolinkmap.com/mcp-repo` for live assistance

Your tools, prompts, and resources are what make your MCP server valuable. They bridge the gap between AI capabilities and your business logic, creating powerful conversational interfaces that let AI assistants help users accomplish real work through your algorithms and data.

---

Next: See the complete [API Reference](api-reference.md) →
