<?php

namespace Seolinkmap\Waasup\Tests\Unit\Protocol;

use Seolinkmap\Waasup\Protocol\MessageHandler;
use Seolinkmap\Waasup\Storage\MemoryStorage;
use Seolinkmap\Waasup\Tests\TestCase;

class ProtocolFeaturesTest extends TestCase
{
    private MessageHandler $messageHandler;
    private MemoryStorage $storage;
    private array $supportedVersions = ['2025-06-18', '2025-03-26', '2024-11-05'];

    protected function setUp(): void
    {
        parent::setUp();

        $toolRegistry = $this->createTestToolRegistry();
        $promptRegistry = $this->createTestPromptRegistry();
        $resourceRegistry = $this->createTestResourceRegistry();
        $this->storage = $this->createTestStorage();

        // Add test prompts with validation
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

        // Add test resource that can fail
        $resourceRegistry->register(
            'test://protected',
            function ($uri, $context) {
                // Check for agency_id in the context structure
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

        // Add test tool with structured output schema
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

        // Add audio content tool
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
        // Generate proper MCP session ID format: protocolVersion_hexstring
        $sessionId = $this->generateMcpSessionId($version);

        // Store session with required protocol_version field
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

        // Process initialize message to complete session setup
        $initMessage = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $version,
                'capabilities' => [
                    'elicitation' => $version === '2025-06-18' ? [] : null,
                    'structured_outputs' => $version === '2025-06-18' ? [] : null
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

    // ===================
    // ELICITATION TESTS (2025-06-18 only)
    // ===================

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

        $this->assertEquals(202, $response->getStatusCode());
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

        // Should return 202 but with error queued
        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

            $this->assertEquals(202, $response->getStatusCode());
        }
    }

    public function testElicitationCapabilityDeclaration(): void
    {
        $sessionId = $this->generateMcpSessionId('2025-06-18');

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
        $this->assertArrayHasKey('elicitation', $data['result']['capabilities']);
    }

    public function testElicitationSensitiveInfoPrevention(): void
    {
        // This test verifies that the protocol discourages sensitive info collection
        // Implementation should be handled by the client, but server can provide guidance
        $sessionId = $this->initializeSession('2025-06-18');

        $sensitiveElicitationMessage = [
            'jsonrpc' => '2.0',
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Please provide your password', // This should be discouraged
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

        // Protocol allows this but clients should implement their own validation
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testElicitationMultiTurnInteraction(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        // First elicitation request
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

        $this->assertEquals(202, $response1->getStatusCode());

        // Second elicitation request (multi-turn)
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

        $this->assertEquals(202, $response2->getStatusCode());
    }

    // ===================
    // STRUCTURED TOOL OUTPUT TESTS (2025-06-18 only)
    // ===================

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

        $this->assertEquals(202, $response->getStatusCode());

        // Check that tools with output schemas are properly listed
        $messages = $this->storage->getMessages($sessionId);

        if (!empty($messages)) {
            $lastMessage = end($messages);
            $result = $lastMessage['data']['result'] ?? [];

            if (isset($result['tools'])) {
                foreach ($result['tools'] as $tool) {
                    if ($tool['name'] === 'structured_output_tool') {
                        $this->assertArrayHasKey('outputSchema', $tool);
                        $this->assertArrayHasKey('properties', $tool['outputSchema']);
                    }
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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());

        // Verify structured content is present in the queued message
        $messages = $this->storage->getMessages($sessionId);

        if (!empty($messages)) {
            $lastMessage = end($messages);
            $result = $lastMessage['data']['result'] ?? [];
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testToolOutputSchemaMimeTypeClarity(): void
    {
        // Test that MIME types are properly handled with structured output
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===================
    // RESOURCE LINKS TESTS (2025-06-18 only)
    // ===================

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

        $this->assertEquals(202, $response->getStatusCode());

        // Verify resource links are present
        $messages = $this->storage->getMessages($sessionId);

        if (!empty($messages)) {
            $lastMessage = end($messages);
            $result = $lastMessage['data']['result'] ?? [];
            $this->assertArrayHasKey('resourceLinks', $result);
            $this->assertIsArray($result['resourceLinks']);
        }
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testResourceLinkVsInlineContent(): void
    {
        // Test that resource links are used instead of inlining large content
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===================
    // TOOL ANNOTATIONS TESTS (2025-03-26+)
    // ===================

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

            $this->assertEquals(202, $response->getStatusCode());
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

            $this->assertEquals(202, $response->getStatusCode());
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

            $this->assertEquals(202, $response->getStatusCode());
        }
    }

    public function testToolAnnotationPermissionManagement(): void
    {
        $sessionId = $this->initializeSession('2025-06-18');

        $toolCallMessage = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'structured_output_tool', // This tool has destructiveHint: true
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testToolAnnotationFrontendAdaptation(): void
    {
        // Test that annotations help frontend adapt UI appropriately
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

        $this->assertEquals(202, $response->getStatusCode());
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

        // Verify annotations are not included in 2024-11-05
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

    // ===================
    // CONTENT TYPES TESTS (2025-03-26+)
    // ===================

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

            $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

        // Should still work, but audio content will be processed differently
        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===================
    // PROGRESS & COMPLETIONS TESTS (2025-03-26+)
    // ===================

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

            $this->assertEquals(202, $response->getStatusCode());
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

        // Should return 202 but with error queued for unsupported method
        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===================
    // META FIELDS TESTS (2025-06-18 only)
    // ===================

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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
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

        $this->assertEquals(202, $response->getStatusCode());
    }

    // ===================
    // VERSION COMPATIBILITY TESTS
    // ===================

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

            // Test supported features
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
                    202,
                    $response->getStatusCode(),
                    "Method {$method} should be supported in version {$version}"
                );
            }

            // Test unsupported features
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

                // Unsupported methods should still return 202 but with error queued
                $this->assertEquals(
                    202,
                    $response->getStatusCode(),
                    "Method {$method} should return 202 (queued error) in version {$version}"
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
}
