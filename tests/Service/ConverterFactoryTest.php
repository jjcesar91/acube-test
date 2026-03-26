<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Job;
use App\Enum\InputFormat;
use App\Enum\OutputFormat;
use App\Service\Conversion\ConverterFactory;
use App\Service\Conversion\ConverterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConverterFactory::class)]
/**
 * Unit tests for ConverterFactory.
 *
 * ConverterFactory is instantiated directly with plain arrays of mock
 */
final class ConverterFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // getConverter() — success paths
    // -------------------------------------------------------------------------

    public function testGetConverterReturnsSupportingConverter(): void
    {
        $job       = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $converter = $this->makeConverter(supports: true);

        $factory = new ConverterFactory([$converter]);

        $this->assertSame($converter, $factory->getConverter($job));
    }

    public function testGetConverterSkipsConvertersThatDoNotSupportJob(): void
    {
        $job          = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Xml);
        $unsupported  = $this->makeConverter(supports: false);
        $supported    = $this->makeConverter(supports: true);

        $factory = new ConverterFactory([$unsupported, $supported]);

        // Factory must iterate past the first and return the second
        $this->assertSame($supported, $factory->getConverter($job));
    }

    public function testGetConverterReturnsFirstSupportingConverterAndStopsEarly(): void
    {
        $job    = new Job('/tmp/input.csv', InputFormat::Csv, OutputFormat::Json);
        $first  = $this->makeConverter(supports: true);
        $second = $this->makeConverter(supports: true);

        // The second converter's supports() should never be evaluated
        $second->expects($this->never())->method('supports');

        $factory = new ConverterFactory([$first, $second]);
        $result  = $factory->getConverter($job);

        $this->assertSame($first, $result);
    }

    // -------------------------------------------------------------------------
    // getConverter() — failure path
    // -------------------------------------------------------------------------

    public function testGetConverterThrowsRuntimeExceptionWhenNoConverterSupports(): void
    {
        $job         = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Json);
        $unsupported = $this->makeConverter(supports: false);

        $factory = new ConverterFactory([$unsupported]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No converter available/');

        $factory->getConverter($job);
    }

    public function testGetConverterThrowsRuntimeExceptionWhenConverterListIsEmpty(): void
    {
        $job     = new Job('/tmp/input.json', InputFormat::Json, OutputFormat::Xml);
        $factory = new ConverterFactory([]);

        $this->expectException(\RuntimeException::class);

        $factory->getConverter($job);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeConverter(bool $supports): ConverterInterface
    {
        $mock = $this->createMock(ConverterInterface::class);
        $mock->method('supports')->willReturn($supports);

        return $mock;
    }
}
