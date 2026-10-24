<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use Zana\Container\Tests\Support\TestServiceCollection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\ThrowsInConstructor;

final class Psr11ComplianceTest extends TestCase
{
    public function testContainerAndScopesArePsrContainers(): void
    {
        $container = (new TestServiceCollection())->singleton(FileLogger::class)->build();

        $this->assertInstanceOf(PsrContainerInterface::class, $container);
        $this->assertInstanceOf(PsrContainerInterface::class, $container->createScope());
    }

    public function testHasIsTrueExactlyWhenGetCanSucceed(): void
    {
        $container = (new TestServiceCollection())->singleton(FileLogger::class)->build();

        $this->assertTrue($container->has(FileLogger::class));
        $this->assertInstanceOf(FileLogger::class, $container->get(FileLogger::class));
        $this->assertFalse($container->has('unknown'));
    }

    public function testMissingServicesThrowNotFoundException(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        (new TestServiceCollection())->build()->get('unknown');
    }

    public function testOtherFailuresAreContainerExceptionsButNotNotFound(): void
    {
        $container = (new TestServiceCollection())->transient(ThrowsInConstructor::class)->build();

        try {
            $container->get(ThrowsInConstructor::class);
            $this->fail('Expected an exception.');
        } catch (ContainerExceptionInterface $e) {
            $this->assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
        }
    }
}
