<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;
use Zana\Container\Tests\Fixtures\FileLogger;

final class ServiceDescriptorTest extends TestCase
{
    public function testHoldsExactlyOneStrategy(): void
    {
        $descriptor = new ServiceDescriptor('logger', ServiceLifetime::Singleton, implementation: FileLogger::class, key: 'file', origin: 'module:Core');

        $this->assertSame('logger', $descriptor->service);
        $this->assertSame(FileLogger::class, $descriptor->implementation);
        $this->assertSame('file', $descriptor->key);
        $this->assertSame('module:Core', $descriptor->origin);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDescriptors(): iterable
    {
        yield 'no strategy' => [[]];
        yield 'two strategies' => [['implementation' => FileLogger::class, 'instance' => new FileLogger()]];
        yield 'three strategies' => [['implementation' => FileLogger::class, 'factory' => static fn () => new FileLogger(), 'instance' => new FileLogger()]];
        yield 'empty key' => [['implementation' => FileLogger::class, 'key' => '']];
        yield 'empty implementation' => [['implementation' => '']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidDescriptors')]
    public function testRejectsInvalidCombinations(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceDescriptor('logger', ServiceLifetime::Transient, ...$arguments);
    }

    public function testRejectsEmptyServiceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceDescriptor('', ServiceLifetime::Transient, implementation: FileLogger::class);
    }
}
