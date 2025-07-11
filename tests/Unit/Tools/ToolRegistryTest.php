<?php

namespace Seolinkmap\Waasup\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Seolinkmap\Waasup\Tools\Registry\ToolRegistry;

class ToolRegistryTest extends TestCase
{
    public function testCanRegisterAndExecuteTool(): void
    {
        $registry = new ToolRegistry();

        $registry->register(
            'test_tool',
            function ($params) {
                return ['result' => 'success', 'input' => $params];
            },
            [
            'description' => 'Test tool'
            ]
        );

        $result = $registry->execute('test_tool', ['test' => 'data']);

        $this->assertEquals('success', $result['result']);
        $this->assertEquals(['test' => 'data'], $result['input']);
    }

    public function testToolsList(): void
    {
        $registry = new ToolRegistry();

        $registry->register(
            'tool1',
            fn () => [],
            [
            'description' => 'First tool'
            ]
        );

        $list = $registry->getToolsList();

        $this->assertArrayHasKey('tools', $list);
        $this->assertCount(1, $list['tools']);
        $this->assertEquals('tool1', $list['tools'][0]['name']);
    }
}
