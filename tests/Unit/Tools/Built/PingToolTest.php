<?php

namespace Seolinkmap\Waasup\Tests\Unit\Tools\Built;

use Seolinkmap\Waasup\Tests\TestCase;
use Seolinkmap\Waasup\Tools\Built\PingTool;

class PingToolTest extends TestCase
{
    private PingTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new PingTool();
    }

    public function testGetName(): void
    {
        $this->assertEquals('ping', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('Test connectivity', $this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('message', $schema['properties']);
    }

    public function testGetAnnotations(): void
    {
        $annotations = $this->tool->getAnnotations();
        $this->assertArrayHasKey('readOnlyHint', $annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertFalse($annotations['destructiveHint']);
    }

    public function testExecuteWithoutParameters(): void
    {
        $result = $this->tool->execute([]);

        $this->assertEquals('pong', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['context_available']);
    }

    public function testExecuteWithMessage(): void
    {
        $result = $this->tool->execute(['message' => 'custom message']);

        $this->assertEquals('pong', $result['status']);
        $this->assertEquals('custom message', $result['message']);
    }

    public function testExecuteWithContext(): void
    {
        $context = ['agency_id' => 1, 'user_id' => 123];
        $result = $this->tool->execute([], $context);

        $this->assertEquals('pong', $result['status']);
        $this->assertTrue($result['context_available']);
    }

    public function testTimestampFormat(): void
    {
        $result = $this->tool->execute([]);

        // Verify timestamp is in ISO 8601 format
        $timestamp = $result['timestamp'];
        $parsed = \DateTime::createFromFormat(\DateTime::ISO8601, $timestamp);
        $this->assertInstanceOf(\DateTime::class, $parsed);
    }
}
