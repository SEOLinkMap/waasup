<?php

namespace Seolinkmap\Waasup\Tests\Unit\Protocol;

use Seolinkmap\Waasup\Protocol\MessageHandler;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

class ProtocolFeaturesTest extends TestCase
{
    private MessageHandler $messageHandler;
    private MemoryStorage $storage;
    private array $supportedVersions = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    protected function setUp(): void
    {
        parent::setUp();

        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();
        $this->storage = $this->createTestStorage();

        $promptRegistry->register(
            'template_prompt',
            function ($arguments, $context) {
                if (empty($arguments['name'])) {
                    throw new \InvalidArgumentException('Name is required');
                }
                $name = $arguments['name'];
                $topic = $arguments['topic'] ?? 'general';
                return [
                    'description' => "Template for {$name} about {$topic}",
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                'type' => 'text',
                                'text' => "Hello {$name}, let's discuss {$topic}."
                            ]
                        ]
                    ]
                ];
            },
            [
                'description' => 'Template prompt requiring name',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Required name'],
                        'topic' => ['type' => 'string', 'description' => 'Optional topic']
                    ],
                    'required' => ['name']
                ]
            ]
        );

        $toolRegistry->register(
            'content_tool',
            function ($params, $context) {
                return [
                    'content' => [
                        ['type' => 'text', 'text' => 'plain text answer'],
                        ['type' => 'image', 'data' => 'aGk=', 'mimeType' => 'image/png']
                    ]
                ];
            },
            ['description' => 'Returns content blocks directly']
        );

        $toolRegistry->register(
            'task_tool',
            function ($params, $context) {
                return ['content' => [['type' => 'text', 'text' => 'task output']]];
            },
            ['description' => 'Supports task execution', 'execution' => ['taskSupport' => 'optional']]
        );

        $toolRegistry->register(
            'task_only_tool',
            function ($params, $context) {
                return ['content' => [['type' => 'text', 'text' => 'task only']]];
            },
            ['description' => 'Requires task execution', 'execution' => ['taskSupport' => 'required']]
        );

        $toolRegistry->register(
            'schema_tool',
            function ($params, $context) {
                return ['content' => [], 'structuredContent' => ['conditions' => 'sunny']];
            },
            [
                'description' => 'Declares an output schema it does not satisfy',
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

        $toolRegistry->register(
            'failing_tool',
            function ($params, $context) {
                throw new \RuntimeException('upstream is down, retry in a minute');
            },
            ['description' => 'Always fails']
        );

        $promptRegistry->register(
            'enum_prompt',
            function ($arguments, $context) {
                return ['description' => 'Enum prompt', 'messages' => []];
            },
            [
                'description' => 'Prompt with an enum argument',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'flavour' => ['type' => 'string', 'enum' => ['vanilla', 'chocolate', 'strawberry']]
                    ]
                ]
            ]
        );

        $resourceRegistry->register(
            'test://protected',
            function ($uri, $context) {

                $agencyId = $context['agency_id'] ?? $context['token_data']['agency_id'] ?? $context['context_data']['id'] ?? null;
                if (empty($agencyId)) {
                    throw new \RuntimeException('Access denied - no agency context');
                }
                return [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => json_encode(['protected' => true, 'agency' => $agencyId])
                        ]
                    ]
                ];
            }
        );

        $toolRegistry->register(
            'structured_output_tool',
            function ($params, $context) {
                return [
                    '_meta' => [
                        'structured' => true,
                        'resourceLinks' => [
                            ['uri' => 'test://resource/123', 'description' => 'Test resource']
                        ]
                    ],
                    'data' => [
                        'id' => $params['id'] ?? 'test-123',
                        'status' => 'success',
                        'result' => $params['input'] ?? 'default'
                    ]
                ];
            },
            [
                'description' => 'Tool with structured output',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'input' => ['type' => 'string']
                    ]
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'result' => ['type' => 'string']
                    ],
                    'required' => ['id', 'status']
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'idempotentHint' => false,
                    'openWorldHint' => true,
                    'experimental' => true,
                    'requiresUserConfirmation' => true,
                    'sensitive' => false
                ]
            ]
        );

        $toolRegistry->register(
            'audio_tool',
            function ($params, $context) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Audio processing complete'
                        ],
                        [
                            'type' => 'audio',
                            'mimeType' => 'audio/mpeg',
                            'data' => base64_encode('fake-audio-data'),
                            'duration' => 5.2
                        ]
                    ]
                ];
            },
            [
                'description' => 'Tool that handles audio content',
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false
                ]
            ]
        );

        $this->messageHandler = new MessageHandler(
            $toolRegistry,
            $promptRegistry,
            $resourceRegistry,
            $this->storage,
            [
                'supported_versions' => $this->supportedVersions,
                'server_info' => [
                    'name' => 'Protocol Features Test Server',
                    'version' => '1.0.0-test'
                ]
            ]
        );
    }

    /**
     * Initialize a session with proper MCP session format
     */
    private function initializeSession(string $version): string
    {

        $sessionId = $this->generateMcpSessionId($version);

        $this->storage->storeSession(
            $sessionId,
            [
                'protocol_version' => $version,
                'agency_id' => 1,
                'user_id' => 1,
                'created_at' => time()
            ],
            3600
        );

        $initMessage = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $version,
                'capabilities' => [
                    'sampling' => [],
                    'roots' => ['listChanged' => true],
                    'elicitation' => strcmp($version, '2025-06-18') >= 0 ? [] : null,
                    'structured_outputs' => strcmp($version, '2025-06-18') >= 0 ? [] : null
                ],
                'clientInfo' => [
                    'name' => 'Test Client',
                    'version' => '1.0.0'
                ]
            ],
            'id' => 1
        ];

        $context = $this->createTestContext(
            [
            'protocol_version' => $version
            ]
        );

        $response = $this->messageHandler->processMessage(
            $initMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Failed to initialize session for version {$version}");
        }

        return $sessionId;
    }

    public function testElicitationCreateRequest(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $elicitationMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Please provide your email address',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => [
                            'type' => 'string',
                            'format' => 'email',
                            'description' => 'Your email address'
                        ]
                    ],
                    'required' => ['email']
                ]
            ],
            'id' => 2
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $elicitationMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testElicitationNotSupportedInOlderVersions(): void
    {
        $sessionId = $this->initializeSession('2025-03-26');

        $elicitationMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'This should fail',
                'requestedSchema' => ['type' => 'object']
            ],
            'id' => 2
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-03-26']);

        $response = $this->messageHandler->processMessage(
            $elicitationMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testElicitationUserResponse(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $responseMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/response',
            'params' => [
                'requestId' => 'elicit-123',
                'action' => 'accept',
                'content' => [
                    'email' => 'user@example.com'
                ]
            ],
            'id' => 3
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $responseMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testElicitationUserCancel(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $cancelMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/response',
            'params' => [
                'requestId' => 'elicit-123',
                'action' => 'reject'
            ],
            'id' => 4
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $cancelMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testElicitationJsonSchemaValidation(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $validSchemas = [
            ['type' => 'string'],
            ['type' => 'number'],
            ['type' => 'boolean'],
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'number']
                ]
            ]
        ];

        $requestId = 5;
        foreach ($validSchemas as $schema) {
            $elicitationMessage = [
                'jsonrpc' => '2.0',
                'method' => 'elicitation/create',
                'params' => [
                    'message' => 'Test schema validation',
                    'requestedSchema' => $schema
                ],
                'id' => $requestId++
            ];

            $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

            $response = $this->messageHandler->processMessage(
                $elicitationMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testElicitationCapabilityDeclaration(): void
    {
        $sessionId = $this->generateMcpSessionId('2025-06-18');

        $this->storage->storeSession(
            $sessionId,
            [
                'protocol_version' => '2025-06-18',
                'agency_id' => 1,
                'user_id' => 1,
                'created_at' => time()
            ],
            3600
        );

        $initMessage = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [
                    'elicitation' => []
                ],
                'clientInfo' => [
                    'name' => 'Elicitation Test Client',
                    'version' => '1.0.0'
                ]
            ],
            'id' => 1
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $initMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('elicitation', $data['result']['capabilities']);
        $this->assertArrayNotHasKey('sampling', $data['result']['capabilities']);
        $this->assertArrayNotHasKey('roots', $data['result']['capabilities']);
    }

    public function testElicitationSensitiveInfoPrevention(): void
    {

        $sessionId = $this->initializeSession('2025-06-18');

        $sensitiveElicitationMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Please provide your password',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'password' => ['type' => 'string']
                    ]
                ]
            ],
            'id' => 6
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $sensitiveElicitationMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testElicitationMultiTurnInteraction(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $firstElicitation = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'What is your name?',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string']
                    ]
                ]
            ],
            'id' => 7
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response1 = $this->messageHandler->processMessage(
            $firstElicitation,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response1->getStatusCode());

        $secondElicitation = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'What is your email?',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email']
                    ]
                ]
            ],
            'id' => 8
        ];

        $response2 = $this->messageHandler->processMessage(
            $secondElicitation,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response2->getStatusCode());
    }

    public function testToolOutputSchemaDeclaration(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolsListMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 9
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolsListMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $result = $responseData['result'] ?? [];

        if (isset($result['tools'])) {
            foreach ($result['tools'] as $tool) {
                if ($tool['name'] === 'structured_output_tool') {
                    $this->assertArrayHasKey('outputSchema', $tool);
                    $this->assertArrayHasKey('properties', $tool['outputSchema']);
                }
            }
        }
    }

    public function testToolOutputSchemaValidation(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'test-123',
                    'input' => 'validation test'
                ]
            ],
            'id' => 10
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToolOutputStructuredContent(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'struct-test',
                    'input' => 'structured content test'
                ]
            ],
            'id' => 11
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $result = $responseData['result'] ?? [];

        if (!empty($result)) {
            $this->assertArrayHasKey('structuredContent', $result);
        }
    }

    public function testToolOutputSchemaTypedResults(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'typed-123',
                    'input' => 'typed results test'
                ]
            ],
            'id' => 12
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToolOutputSchemaMimeTypeClarity(): void
    {

        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'mime-test',
                    'input' => 'mime type test'
                ]
            ],
            'id' => 13
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResourceLinkInToolResult(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'resource-link-test',
                    'input' => 'test resource links'
                ]
            ],
            'id' => 14
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $result = $responseData['result'] ?? [];

        $this->assertArrayHasKey('content', $result);

        $linkTypes = array_column($result['content'], 'type');
        $this->assertContains('resource_link', $linkTypes);
    }

    public function testResourceLinkUriReference(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'uri-ref-test',
                    'input' => 'test URI references'
                ]
            ],
            'id' => 15
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResourceLinkVsInlineContent(): void
    {

        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'inline-vs-link-test',
                    'input' => 'test content strategy'
                ]
            ],
            'id' => 16
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToolAnnotationReadOnlyHint(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $toolsListMessage = [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 17
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $toolsListMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testToolAnnotationDestructiveHint(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $toolsListMessage = [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 18
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $toolsListMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testToolAnnotationMetadata(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $toolsListMessage = [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 19
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $toolsListMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testToolAnnotationPermissionManagement(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'permission-test',
                    'input' => 'test permissions'
                ]
            ],
            'id' => 20
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToolAnnotationFrontendAdaptation(): void
    {

        $sessionId = $this->initializeSession('2025-06-18');

        $toolsListMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 21
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolsListMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testToolAnnotationsNotAvailableInOldVersions(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        $toolsListMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 22
        ];

        $context = $this->createTestContext(['protocol_version' => '2024-11-05']);

        $response = $this->messageHandler->processMessage(
            $toolsListMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());

        $messages = $this->storage->getMessages($sessionId);

        if (!empty($messages)) {
            $lastMessage = end($messages);
            $result = $lastMessage['data']['result'] ?? [];

            if (isset($result['tools'])) {
                foreach ($result['tools'] as $tool) {
                    $this->assertArrayNotHasKey('annotations', $tool);
                }
            }
        }
    }

    public function testAudioDataSupport(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $toolCallMessage = [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'audio_tool',
                    'arguments' => [
                        'audioFile' => [
                            'type' => 'audio',
                            'mimeType' => 'audio/wav',
                            'data' => base64_encode('fake-audio-data')
                        ]
                    ]
                ],
                'id' => 23
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $toolCallMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testAudioContentTypeHandling(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'audio_tool',
                'arguments' => []
            ],
            'id' => 24
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testMultimodalContentIntegration(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'audio_tool',
                'arguments' => [
                    'includeText' => true,
                    'includeAudio' => true
                ]
            ],
            'id' => 25
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testContentTypeValidation(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'audio_tool',
                'arguments' => [
                    'invalidAudio' => [
                        'type' => 'audio',
                        'mimeType' => 'audio/invalid',
                        'data' => 'not-base64-data'
                    ]
                ]
            ],
            'id' => 26
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAudioNotSupportedInOldVersions(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'audio_tool',
                'arguments' => []
            ],
            'id' => 27
        ];

        $context = $this->createTestContext(['protocol_version' => '2024-11-05']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testProgressNotificationWithMessage(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $progressMessage = [
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => [
                    'progress' => 50,
                    'total' => 100,
                    'message' => 'Processing data...'
                ]
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $progressMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(202, $response->getStatusCode());
        }
    }

    public function testProgressNotificationDescriptiveUpdates(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $progressMessage = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => [
                'progress' => 75,
                'total' => 100,
                'message' => 'Finalizing results and preparing output...'
            ]
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $progressMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testCompletionsCapabilityDeclaration(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->generateMcpSessionId($version);

            $this->storage->storeSession(
                $sessionId,
                [
                    'protocol_version' => $version,
                    'agency_id' => 1,
                    'user_id' => 1,
                    'created_at' => time()
                ],
                3600
            );

            $initMessage = [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $version,
                    'capabilities' => [
                        'completions' => []
                    ],
                    'clientInfo' => [
                        'name' => 'Completions Test Client',
                        'version' => '1.0.0'
                    ]
                ],
                'id' => 1
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $initMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
            $data = json_decode((string) $response->getBody(), true);
            $this->assertArrayHasKey('completions', $data['result']['capabilities']);
        }
    }

    public function testArgumentAutocompletionSuggestions(): void
    {
        foreach (['2025-06-18', '2025-03-26'] as $version) {
            $sessionId = $this->initializeSession($version);

            $completionsMessage = [
                'jsonrpc' => '2.0',
                'method' => 'completions/complete',
                'params' => [
                    'ref' => [
                        'type' => 'ref/tool',
                        'name' => 'test_tool'
                    ],
                    'argument' => 'mes'
                ],
                'id' => 28
            ];

            $context = $this->createTestContext(['protocol_version' => $version]);

            $response = $this->messageHandler->processMessage(
                $completionsMessage,
                $sessionId,
                $context,
                $this->createResponse()
            );

            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testCompletionsNotAvailableInOldVersions(): void
    {
        $sessionId = $this->initializeSession('2024-11-05');

        $completionsMessage = [
            'jsonrpc' => '2.0',
            'method' => 'completions/complete',
            'params' => [
                'ref' => [
                    'type' => 'ref/tool',
                    'name' => 'test_tool'
                ],
                'argument' => 'test'
            ],
            'id' => 29
        ];

        $context = $this->createTestContext(['protocol_version' => '2024-11-05']);

        $response = $this->messageHandler->processMessage(
            $completionsMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testMetaFieldInInterfaces(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'meta-field-test',
                    'input' => 'test meta fields'
                ]
            ],
            'id' => 30
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testMetaFieldUsageSpecification(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $completionMessage = [
            'jsonrpc' => '2.0',
            'method' => 'completions/complete',
            'params' => [
                'ref' => [
                    'type' => 'ref/tool',
                    'name' => 'structured_output_tool'
                ],
                'argument' => 'id',
                '_meta' => [
                    'context' => 'completion_request',
                    'timestamp' => time()
                ]
            ],
            'id' => 31
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $completionMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testMetaFieldProperUsage(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool',
                'arguments' => [
                    'id' => 'meta-usage-test',
                    'input' => 'proper meta usage',
                    '_meta' => [
                        'requestContext' => 'test_suite',
                        'executionMode' => 'validation'
                    ]
                ]
            ],
            'id' => 32
        ];

        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            $toolCallMessage,
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testVersionSpecificFeatureGating(): void
    {
        $featureTests = [
            '2024-11-05' => [
                'supported' => ['tools/list', 'ping', 'resources/list'],
                'unsupported' => ['elicitation/create', 'completions/complete']
            ],
            '2025-03-26' => [
                'supported' => ['tools/list', 'ping', 'completions/complete'],
                'unsupported' => ['elicitation/create']
            ],
            '2025-06-18' => [
                'supported' => ['tools/list', 'ping', 'elicitation/create', 'completions/complete'],
                'unsupported' => []
            ]
        ];

        foreach ($featureTests as $version => $tests) {
            $sessionId = $this->initializeSession($version);
            $context = $this->createTestContext(['protocol_version' => $version]);

            $expectedStatus = strcmp($version, '2025-03-26') >= 0 ? 200 : 202;

            foreach ($tests['supported'] as $method) {
                $message = [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $this->getDefaultParamsForMethod($method),
                    'id' => rand(1000, 9999)
                ];

                $response = $this->messageHandler->processMessage(
                    $message,
                    $sessionId,
                    $context,
                    $this->createResponse()
                );

                $this->assertEquals(
                    $expectedStatus,
                    $response->getStatusCode(),
                    "Method {$method} should be supported in version {$version}"
                );
            }

            foreach ($tests['unsupported'] as $method) {
                $message = [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $this->getDefaultParamsForMethod($method),
                    'id' => rand(1000, 9999)
                ];

                $response = $this->messageHandler->processMessage(
                    $message,
                    $sessionId,
                    $context,
                    $this->createResponse()
                );

                $this->assertEquals(
                    $expectedStatus,
                    $response->getStatusCode(),
                    "Method {$method} should return a {$expectedStatus} error in version {$version}"
                );
            }
        }
    }

    private function getDefaultParamsForMethod(string $method): array
    {
        switch ($method) {
            case 'elicitation/create':
                return [
                        'message' => 'Test message',
                        'requestedSchema' => ['type' => 'string']
                    ];
            case 'completions/complete':
                return [
                        'ref' => ['type' => 'ref/tool', 'name' => 'test_tool'],
                        'argument' => 'test'
                    ];
            case 'tools/call':
                return [
                        'name' => 'test_tool',
                        'arguments' => []
                    ];
            default:
                return [];
        }
    }

    public function testInitializeNegotiates20251125(): void
    {
        $sessionId = $this->generateMcpSessionId('2025-11-25');

        $response = $this->messageHandler->handleInitialize(
            ['protocolVersion' => '2025-11-25'],
            1,
            $sessionId,
            '2025-11-25',
            $this->createResponse()
        );

        $data = json_decode((string) $response->getBody(), true);

        $this->assertEquals('2025-11-25', $data['result']['protocolVersion']);
        $this->assertArrayHasKey('completions', $data['result']['capabilities']);
        $this->assertArrayHasKey('logging', $data['result']['capabilities']);
        $this->assertArrayNotHasKey('sampling', $data['result']['capabilities']);
    }

    public function testToolContentIsNotReserialized(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'content_tool', 'arguments' => []],
                'id' => 501
            ],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $result = json_decode((string) $response->getBody(), true)['result'];

        $this->assertEquals('text', $result['content'][0]['type']);
        $this->assertEquals('plain text answer', $result['content'][0]['text']);
        $this->assertEquals('image', $result['content'][1]['type']);
        $this->assertFalse($result['isError']);
    }

    public function testToolFailureIsReportedAsToolError(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'failing_tool', 'arguments' => []],
                'id' => 502
            ],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $data = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('result', $data);
        $this->assertTrue($data['result']['isError']);
        $this->assertStringContainsString('upstream is down', $data['result']['content'][0]['text']);
    }

    public function testUnknownToolIsAProtocolError(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'no_such_tool', 'arguments' => []],
                'id' => 503
            ],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $data = json_decode((string) $response->getBody(), true);

        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('tools/list', $data['error']['message']);
    }

    public function testPingReturnsAnEmptyResult(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 504],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $this->assertStringContainsString('"result":{}', (string) $response->getBody());
    }

    public function testCompletionCompleteReturnsSpecShape(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'completion/complete',
                'params' => [
                    'ref' => ['type' => 'ref/prompt', 'name' => 'enum_prompt'],
                    'argument' => ['name' => 'flavour', 'value' => 'va']
                ],
                'id' => 505
            ],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $completion = json_decode((string) $response->getBody(), true)['result']['completion'];

        $this->assertEquals(['vanilla'], $completion['values']);
        $this->assertEquals(1, $completion['total']);
        $this->assertFalse($completion['hasMore']);
    }

    public function testClientResponseToServerRequestIsStored(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $requestId = $this->messageHandler->requestSampling(
            $sessionId,
            [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
            ['maxTokens' => 10]
        );

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'id' => $requestId,
                'result' => ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'hello'], 'model' => 'test-model']
            ],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $this->assertEquals(202, $response->getStatusCode());

        $stored = $this->storage->getSamplingResponse($sessionId, $requestId);

        $this->assertNotNull($stored);
        $this->assertEquals('sampling_response', $stored['data']['type']);
        $this->assertEquals('test-model', $stored['data']['result']['model']);
    }

    public function testSamplingRequestOmitsUnsetOptions(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $this->messageHandler->requestSampling(
            $sessionId,
            [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
            ['maxTokens' => 50]
        );

        $messages = $this->storage->getMessages($sessionId);
        $params = $messages[0]['data']['params'];

        $this->assertEquals(50, $params['maxTokens']);
        $this->assertArrayNotHasKey('temperature', $params);
        $this->assertArrayNotHasKey('stopSequences', $params);
    }

    public function testResourceSubscriptionGatesUpdateNotifications(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $this->messageHandler->sendResourceUpdatedNotification($sessionId, 'test://protected');
        $this->assertCount(0, $this->storage->getMessages($sessionId));

        $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'resources/subscribe',
                'params' => ['uri' => 'test://protected'],
                'id' => 506
            ],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->messageHandler->sendResourceUpdatedNotification($sessionId, 'test://protected');

        $messages = $this->storage->getMessages($sessionId);
        $this->assertCount(1, $messages);
        $this->assertEquals('notifications/resources/updated', $messages[0]['data']['method']);
    }

    public function testLogMessagesHonourTheLevelTheClientSet(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $this->messageHandler->sendLogMessage($sessionId, 'error', 'before');
        $this->assertCount(0, $this->storage->getMessages($sessionId));

        $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'logging/setLevel',
                'params' => ['level' => 'warning'],
                'id' => 507
            ],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->messageHandler->sendLogMessage($sessionId, 'debug', 'too quiet');
        $this->assertCount(0, $this->storage->getMessages($sessionId));

        $this->messageHandler->sendLogMessage($sessionId, 'error', 'loud enough');

        $messages = $this->storage->getMessages($sessionId);
        $this->assertCount(1, $messages);
        $this->assertEquals('notifications/message', $messages[0]['data']['method']);
        $this->assertEquals('loud enough', $messages[0]['data']['params']['data']);
    }

    public function testProgressNotificationsNeedAProgressToken(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $this->messageHandler->processMessage(
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 508],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->messageHandler->sendProgressNotification($sessionId, 10, 'working');
        $this->assertCount(0, $this->storage->getMessages($sessionId));

        $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'ping',
                'params' => ['_meta' => ['progressToken' => 'tok-1']],
                'id' => 509
            ],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $this->messageHandler->sendProgressNotification($sessionId, 10, 'working', 100);

        $messages = $this->storage->getMessages($sessionId);
        $params = $messages[0]['data']['params'];

        $this->assertEquals('tok-1', $params['progressToken']);
        $this->assertEquals(10, $params['progress']);
        $this->assertEquals(100, $params['total']);
        $this->assertEquals('working', $params['message']);
    }

    public function testCancellationDropsOnlyTheCancelledRequest(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $keep = $this->messageHandler->requestRootsList($sessionId);
        $cancel = $this->messageHandler->requestRootsList($sessionId);

        $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => $cancel]
            ],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $remaining = array_column(array_column($this->storage->getMessages($sessionId), 'data'), 'id');

        $this->assertContains($keep, $remaining);
        $this->assertNotContains($cancel, $remaining);
    }

    public function testIconsAreGatedByProtocolVersion(): void
    {
        $icon = [['src' => 'https://example.com/i.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']]];

        $tools = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();
        $tools->register('icon_tool', fn ($p, $c) => [], ['description' => 'd', 'icons' => $icon]);

        $prompts = new \Seolinkmap\Waasup\Prompts\Registry\PromptRegistry();
        $prompts->register('icon_prompt', fn ($a, $c) => [], ['description' => 'd', 'icons' => $icon]);

        $resources = new \Seolinkmap\Waasup\Resources\Registry\ResourceRegistry();
        $resources->register('res://icon', fn ($u, $c) => [], ['icons' => $icon]);
        $resources->registerTemplate('res://icon/{id}', fn ($u, $c) => [], ['icons' => $icon]);

        $this->assertArrayHasKey('icons', $tools->getToolsList('2025-11-25')['tools'][0]);
        $this->assertArrayHasKey('icons', $prompts->getPromptsList('2025-11-25')['prompts'][0]);
        $this->assertArrayHasKey('icons', $resources->getResourcesList('2025-11-25')['resources'][0]);
        $this->assertArrayHasKey('icons', $resources->getResourceTemplatesList('2025-11-25')['resourceTemplates'][0]);

        $this->assertArrayNotHasKey('icons', $tools->getToolsList('2025-06-18')['tools'][0]);
        $this->assertArrayNotHasKey('icons', $prompts->getPromptsList('2025-06-18')['prompts'][0]);
        $this->assertArrayNotHasKey('icons', $resources->getResourcesList('2025-06-18')['resources'][0]);
        $this->assertArrayNotHasKey('icons', $resources->getResourceTemplatesList('2025-06-18')['resourceTemplates'][0]);
    }

    public function testTitlesAreGatedByProtocolVersion(): void
    {
        $tools = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();
        $tools->register('titled_tool', fn ($p, $c) => [], ['description' => 'd', 'title' => 'Titled Tool']);

        $prompts = new \Seolinkmap\Waasup\Prompts\Registry\PromptRegistry();
        $prompts->register('titled_prompt', fn ($a, $c) => [], ['description' => 'd', 'title' => 'Titled Prompt']);

        $resources = new \Seolinkmap\Waasup\Resources\Registry\ResourceRegistry();
        $resources->register('res://titled', fn ($u, $c) => [], ['title' => 'Titled Resource']);
        $resources->registerTemplate('res://titled/{id}', fn ($u, $c) => [], ['title' => 'Titled Template']);

        $this->assertEquals('Titled Tool', $tools->getToolsList('2025-06-18')['tools'][0]['title']);
        $this->assertEquals('Titled Prompt', $prompts->getPromptsList('2025-06-18')['prompts'][0]['title']);
        $this->assertEquals('Titled Resource', $resources->getResourcesList('2025-06-18')['resources'][0]['title']);
        $this->assertEquals('Titled Template', $resources->getResourceTemplatesList('2025-06-18')['resourceTemplates'][0]['title']);

        $this->assertArrayNotHasKey('title', $tools->getToolsList('2025-03-26')['tools'][0]);
        $this->assertArrayNotHasKey('title', $prompts->getPromptsList('2025-03-26')['prompts'][0]);
        $this->assertArrayNotHasKey('title', $resources->getResourcesList('2025-03-26')['resources'][0]);
        $this->assertArrayNotHasKey('title', $resources->getResourceTemplatesList('2025-03-26')['resourceTemplates'][0]);
    }

    public function testServerRefusesRequestsTheClientCannotAnswer(): void
    {
        $sessionId = $this->generateMcpSessionId('2025-11-25');

        $this->messageHandler->handleInitialize(
            ['protocolVersion' => '2025-11-25', 'capabilities' => ['roots' => []]],
            1,
            $sessionId,
            '2025-11-25',
            $this->createResponse()
        );

        $this->messageHandler->requestRootsList($sessionId);
        $this->assertCount(1, $this->storage->getMessages($sessionId));

        $this->expectException(\Seolinkmap\Waasup\Exception\ProtocolException::class);
        $this->expectExceptionMessage("did not declare the 'sampling' capability");

        $this->messageHandler->requestSampling(
            $sessionId,
            [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
            ['maxTokens' => 10]
        );
    }

    public function testStructuredContentIsCheckedAgainstOutputSchema(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $response = $this->messageHandler->processMessage(
            [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'schema_tool', 'arguments' => []],
                'id' => 601
            ],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $data = json_decode((string) $response->getBody(), true);

        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertStringContainsString("required property 'temperature' is missing", $data['error']['message']);
    }

    public function testDuplicateRequestIdsAreRefusedAcrossWorkers(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);
        $ping = ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 'reused-id'];

        $this->messageHandler->processMessage($ping, $sessionId, $context, $this->createResponse());

        $otherWorker = new MessageHandler(
            $this->createTestToolRegistry(),
            $this->createTestPromptRegistry(),
            $this->createTestResourceRegistry(),
            $this->storage,
            ['supported_versions' => $this->supportedVersions]
        );

        $this->expectException(\Seolinkmap\Waasup\Exception\ProtocolException::class);
        $this->expectExceptionMessage('Duplicate request id reused-id');

        $otherWorker->processMessage($ping, $sessionId, $context, $this->createResponse());
    }

    public function testListsPaginateWithOpaqueCursors(): void
    {
        $tools = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $name) {
            $tools->register($name, fn ($p, $c) => [], ['description' => $name]);
        }

        $handler = new MessageHandler(
            $tools,
            $this->createTestPromptRegistry(),
            $this->createTestResourceRegistry(),
            $this->storage,
            [
                'supported_versions' => $this->supportedVersions,
                'pagination' => ['page_size' => 2]
            ]
        );

        $sessionId = $this->initializeSession('2025-11-25');
        $context = $this->createTestContext(['protocol_version' => '2025-11-25']);

        $seen = [];
        $cursor = null;
        $requestId = 700;

        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];

            $response = $handler->processMessage(
                ['jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => $params, 'id' => $requestId++],
                $sessionId,
                $context,
                $this->createResponse()
            );

            $result = json_decode((string) $response->getBody(), true)['result'];

            $this->assertLessThanOrEqual(2, count($result['tools']));

            $seen = array_merge($seen, array_column($result['tools'], 'name'));
            $cursor = $result['nextCursor'] ?? null;
        } while ($cursor !== null);

        $this->assertEquals(['alpha', 'bravo', 'charlie', 'delta', 'echo'], $seen);
    }

    public function testFinalPageOmitsNextCursor(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $response = $this->messageHandler->processMessage(
            ['jsonrpc' => '2.0', 'method' => 'prompts/list', 'id' => 710],
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        $result = json_decode((string) $response->getBody(), true)['result'];

        $this->assertArrayNotHasKey('nextCursor', $result);
    }

    public function testStreamResumesFromLastEventId(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        foreach (['one', 'two', 'three'] as $name) {
            $this->storage->storeMessage($sessionId, ['jsonrpc' => '2.0', 'method' => "notifications/{$name}"]);
        }

        $all = $this->storage->getMessages($sessionId);
        $this->assertCount(3, $all);

        $afterFirst = $this->storage->getMessages($sessionId, [], (string)$all[0]['id']);
        $this->assertCount(2, $afterFirst);
        $this->assertEquals('notifications/two', $afterFirst[0]['data']['method']);

        $afterLast = $this->storage->getMessages($sessionId, [], (string)$all[2]['id']);
        $this->assertSame([], $afterLast);
    }

    public function testToolNamesAreValidatedAtRegistration(): void
    {
        $registry = new \Seolinkmap\Waasup\Tools\Registry\ToolRegistry();

        $registry->register('valid.tool-name_9', fn ($p, $c) => [], ['description' => 'ok']);
        $this->assertTrue($registry->hasTool('valid.tool-name_9'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('may only contain letters');

        $registry->register('invalid name', fn ($p, $c) => [], ['description' => 'bad']);
    }

    public function testRefreshTokenReuseRevokesTheFamily(): void
    {
        $storage = new MemoryStorage();

        $storage->storeAccessToken(
            [
                'client_id' => 'c1',
                'user_id' => 7,
                'access_token' => 'access-old',
                'refresh_token' => 'refresh-old',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1
            ]
        );
        $storage->storeAccessToken(
            [
                'client_id' => 'c1',
                'user_id' => 7,
                'access_token' => 'access-new',
                'refresh_token' => 'refresh-new',
                'scope' => 'mcp:read',
                'expires_at' => time() + 3600,
                'agency_id' => 1
            ]
        );

        $this->assertTrue($storage->revokeTokenFamily('refresh-old'));
        $this->assertNull($storage->getTokenByRefreshToken('refresh-new', 'c1'));
        $this->assertFalse($storage->revokeTokenFamily('never-issued'));
    }

    private function call(string $sessionId, string $method, array $params, int $id): array
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'id' => $id];

        if ($params !== []) {
            $message['params'] = $params;
        }

        $response = $this->messageHandler->processMessage(
            $message,
            $sessionId,
            $this->createTestContext(['protocol_version' => '2025-11-25']),
            $this->createResponse()
        );

        return json_decode((string) $response->getBody(), true);
    }

    public function testTaskAugmentedCallReturnsATaskAndKeepsItsResult(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $created = $this->call($sessionId, 'tools/call', ['name' => 'task_tool', 'arguments' => [], 'task' => ['ttl' => 60000]], 801);
        $task = $created['result']['task'];

        $this->assertNotEmpty($task['taskId']);
        $this->assertEquals(60000, $task['ttl']);
        $this->assertNotEmpty($task['createdAt']);
        $this->assertNotEmpty($task['lastUpdatedAt']);
        $this->assertArrayNotHasKey('content', $created['result']);

        $fetched = $this->call($sessionId, 'tasks/get', ['taskId' => $task['taskId']], 802);
        $this->assertEquals($task['taskId'], $fetched['result']['taskId']);
        $this->assertContains($fetched['result']['status'], ['completed', 'working']);

        $result = $this->call($sessionId, 'tasks/result', ['taskId' => $task['taskId']], 803);
        $this->assertEquals('task output', $result['result']['content'][0]['text']);
        $this->assertEquals(
            ['taskId' => $task['taskId']],
            $result['result']['_meta']['io.modelcontextprotocol/related-task']
        );

        $listed = $this->call($sessionId, 'tasks/list', [], 804);
        $this->assertCount(1, $listed['result']['tasks']);
        $this->assertArrayNotHasKey('result', $listed['result']['tasks'][0]);
    }

    public function testTaskSupportIsEnforcedPerTool(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $forbidden = $this->call($sessionId, 'tools/call', ['name' => 'test_tool', 'arguments' => [], 'task' => []], 811);
        $this->assertEquals(-32601, $forbidden['error']['code']);

        $required = $this->call($sessionId, 'tools/call', ['name' => 'task_only_tool', 'arguments' => []], 812);
        $this->assertEquals(-32601, $required['error']['code']);

        $ok = $this->call($sessionId, 'tools/call', ['name' => 'task_only_tool', 'arguments' => [], 'task' => []], 813);
        $this->assertArrayHasKey('task', $ok['result']);
    }

    public function testTaskErrorsFollowTheSpecifiedCodes(): void
    {
        $sessionId = $this->initializeSession('2025-11-25');

        $unknown = $this->call($sessionId, 'tasks/get', ['taskId' => 'does-not-exist'], 821);
        $this->assertEquals(-32602, $unknown['error']['code']);

        $created = $this->call($sessionId, 'tools/call', ['name' => 'task_tool', 'arguments' => [], 'task' => []], 822);
        $taskId = $created['result']['task']['taskId'];

        $cancelled = $this->call($sessionId, 'tasks/cancel', ['taskId' => $taskId], 823);
        $this->assertEquals(-32602, $cancelled['error']['code']);
        $this->assertStringContainsString('terminal status', $cancelled['error']['message']);
    }

    public function testTasksAreAbsentBefore20251125(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');
        $context = $this->createTestContext(['protocol_version' => '2025-06-18']);

        $response = $this->messageHandler->processMessage(
            ['jsonrpc' => '2.0', 'method' => 'tasks/get', 'params' => ['taskId' => 'x'], 'id' => 831],
            $sessionId,
            $context,
            $this->createResponse()
        );

        $data = json_decode((string) $response->getBody(), true);
        $this->assertEquals(-32601, $data['error']['code']);

        $tools = $this->messageHandler->processMessage(
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 832],
            $sessionId,
            $context,
            $this->createResponse()
        );

        foreach (json_decode((string) $tools->getBody(), true)['result']['tools'] as $tool) {
            $this->assertArrayNotHasKey('execution', $tool);
        }
    }

    public function testBothAuthorizationServerDiscoveryDocumentsAreServed(): void
    {
        $provider = new \Seolinkmap\Waasup\Discovery\WellKnownProvider(
            ['oauth' => ['base_url' => 'https://auth.example.com']]
        );

        foreach (['authorizationServer', 'openidConfiguration'] as $method) {
            $request = $this->createRequest('GET', '/.well-known/x')
                ->withHeader('MCP-Protocol-Version', '2025-11-25');

            $response = $provider->{$method}($request, $this->createResponse());
            $document = json_decode((string) $response->getBody(), true);

            $this->assertEquals('https://auth.example.com', $document['issuer']);
            $this->assertEquals(['S256'], $document['code_challenge_methods_supported']);
            $this->assertArrayHasKey('authorization_endpoint', $document);
            $this->assertArrayHasKey('token_endpoint', $document);
        }
    }

}
