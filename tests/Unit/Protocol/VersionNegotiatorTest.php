<?php

namespace Seolinkmap\Waasup\Tests\Unit\Protocol;

use Seolinkmap\Waasup\Protocol\VersionNegotiator;
use Seolinkmap\Waasup\Tests\TestCase;

class VersionNegotiatorTest extends TestCase
{
    private VersionNegotiator $negotiator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->negotiator = new VersionNegotiator(['2025-03-18', '2024-11-05', '2024-06-25']);
    }

    public function testNegotiateExactMatch(): void
    {
        $result = $this->negotiator->negotiate('2024-11-05');
        $this->assertEquals('2024-11-05', $result);
    }

    public function testNegotiateNewerClientVersion(): void
    {

        $result = $this->negotiator->negotiate('2026-01-01');
        $this->assertEquals('2025-11-25', $result);
    }

    public function testNegotiateFallbackToNewest(): void
    {
        $result = $this->negotiator->negotiate('2023-01-01');
        $this->assertEquals('2025-11-25', $result);
    }

    public function testNegotiateUnparseableVersion(): void
    {
        $this->assertEquals('2025-11-25', $this->negotiator->negotiate('not-a-version'));
        $this->assertEquals('2025-11-25', $this->negotiator->negotiate(''));
    }

    public function testNegotiateWithDefaultVersions(): void
    {
        $defaultNegotiator = new VersionNegotiator();

        $result = $defaultNegotiator->negotiate('2024-11-05');
        $this->assertEquals('2024-11-05', $result);
    }

    public function testIsSupported(): void
    {
        $this->assertTrue($this->negotiator->isSupported('2024-11-05'));
        $this->assertTrue($this->negotiator->isSupported('2025-03-26'));
        $this->assertTrue($this->negotiator->isSupported('2025-06-18'));
        $this->assertFalse($this->negotiator->isSupported('2023-01-01'));
        $this->assertFalse($this->negotiator->isSupported('2026-01-01'));
    }

    public function testGetSupportedVersions(): void
    {
        $versions = $this->negotiator->getSupportedVersions();
        $expected = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

        $this->assertEquals($expected, $versions);
    }

    public function testVersionOrdering(): void
    {

        $result1 = $this->negotiator->negotiate('2024-12-01');
        $this->assertEquals('2024-11-05', $result1);

        $result2 = $this->negotiator->negotiate('2025-05-01');
        $this->assertEquals('2025-03-26', $result2);
    }

    public function testEmptyVersionsArray(): void
    {
        $emptyNegotiator = new VersionNegotiator([]);

        $this->expectNotToPerformAssertions();
        $emptyNegotiator->negotiate('2024-11-05');
    }
}
