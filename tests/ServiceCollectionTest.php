<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\ContainerException;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceLifetime;
use Zana\Container\Tests\Fixtures\EmailNotifier;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\NotifierInterface;
use Zana\Container\Tests\Fixtures\NullLogger;
use Zana\Container\Tests\Fixtures\PrefixLogger;
use Zana\Container\Tests\Fixtures\SmsNotifier;

final class ServiceCollectionTest extends TestCase
{
    public function testEachStrategyBecomesADescriptor(): void
    {
        $factory = static fn (): FileLogger => new FileLogger();
        $instance = new NullLogger();

        $services = (new ServiceCollection())
            ->singleton(FileLogger::class)
            ->scoped(LoggerInterface::class, FileLogger::class, origin: 'module:Core')
            ->transient('factory-logger', $factory, key: 'fast')
            ->instance(NullLogger::class, $instance);

        [$self, $class, $byFactory, $byInstance] = $services->descriptors();

        $this->assertSame([FileLogger::class, FileLogger::class, ServiceLifetime::Singleton], [$self->service, $self->implementation, $self->lifetime]);
        $this->assertSame([FileLogger::class, ServiceLifetime::Scoped, 'module:Core'], [$class->implementation, $class->lifetime, $class->origin]);
        $this->assertSame([$factory, 'fast', ServiceLifetime::Transient], [$byFactory->factory, $byFactory->key, $byFactory->lifetime]);
        $this->assertSame([$instance, ServiceLifetime::Singleton], [$byInstance->instance, $byInstance->lifetime]);
    }

    public function testRegisteringAgainAppends(): void
    {
        $services = (new ServiceCollection())
            ->transient(NotifierInterface::class, EmailNotifier::class)
            ->transient(NotifierInterface::class, SmsNotifier::class)
            ->instance(NotifierInterface::class, new EmailNotifier());

        $this->assertCount(3, $services->descriptors());
    }

    public function testTryRegistersOnlyWhenAbsent(): void
    {
        $services = (new ServiceCollection())
            ->trySingleton(LoggerInterface::class, FileLogger::class)
            ->trySingleton(LoggerInterface::class, NullLogger::class)
            ->tryScoped(LoggerInterface::class, NullLogger::class, key: 'null')
            ->tryTransient(LoggerInterface::class, FileLogger::class, key: 'null');

        $implementations = array_map(static fn ($d) => [$d->implementation, $d->key], $services->descriptors());

        $this->assertSame([[FileLogger::class, null], [NullLogger::class, 'null']], $implementations);
    }

    public function testTryIgnoresAliases(): void
    {
        $services = (new ServiceCollection())
            ->alias(LoggerInterface::class, FileLogger::class)
            ->tryTransient(LoggerInterface::class, NullLogger::class);

        $this->assertCount(1, $services->descriptors());
    }

    public function testReplaceKeepsTheLastLifetimeByDefault(): void
    {
        $services = (new ServiceCollection())
            ->transient(LoggerInterface::class, FileLogger::class)
            ->scoped(LoggerInterface::class, FileLogger::class)
            ->singleton(LoggerInterface::class, FileLogger::class, key: 'other')
            ->replace(LoggerInterface::class, NullLogger::class);

        $unkeyed = array_values(array_filter($services->descriptors(), static fn ($d) => $d->key === null));

        $this->assertCount(1, $unkeyed);
        $this->assertSame([NullLogger::class, ServiceLifetime::Scoped], [$unkeyed[0]->implementation, $unkeyed[0]->lifetime]);
        $this->assertCount(2, $services->descriptors(), 'the keyed registration is untouched');
    }

    public function testReplaceAcceptsAnExplicitLifetime(): void
    {
        $services = (new ServiceCollection())
            ->transient(LoggerInterface::class, FileLogger::class)
            ->replace(LoggerInterface::class, NullLogger::class, lifetime: ServiceLifetime::Singleton);

        $this->assertSame(ServiceLifetime::Singleton, $services->descriptors()[0]->lifetime);
    }

    public function testReplaceKeepsTagsDecoratorsAndAliases(): void
    {
        $services = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->alias('logger', LoggerInterface::class)
            ->tag('loggers', LoggerInterface::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->replace(LoggerInterface::class, NullLogger::class);

        $container = $services->build();

        $logger = $container->get('logger');
        $this->assertInstanceOf(PrefixLogger::class, $logger);
        $this->assertInstanceOf(NullLogger::class, $logger->inner);
        $this->assertSame([$logger], $container->tagged('loggers'));
    }

    public function testReplaceWithoutRegistrationThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot replace Zana\\Container\\Tests\\Fixtures\\LoggerInterface: it has no registration with key 'x'");

        (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->replace(LoggerInterface::class, NullLogger::class, key: 'x');
    }

    public function testRemoveWithoutKeyRemovesAliasTagsAndDecorators(): void
    {
        $services = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(LoggerInterface::class, FileLogger::class, key: 'file')
            ->alias(LoggerInterface::class . 'Alias', LoggerInterface::class)
            ->tag('loggers', LoggerInterface::class, FileLogger::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->singleton(FileLogger::class)
            ->remove(LoggerInterface::class)
            ->remove(LoggerInterface::class . 'Alias');

        $this->assertFalse($services->has(LoggerInterface::class));
        $this->assertTrue($services->has(LoggerInterface::class, 'file'));
        $this->assertFalse($services->has(LoggerInterface::class . 'Alias'));

        $container = $services->build();
        $this->assertCount(1, $container->tagged('loggers'));
        $this->assertInstanceOf(FileLogger::class, $container->getKeyed(LoggerInterface::class, 'file'), 'no decorator is left');
    }

    public function testRemoveWithKeyOnlyRemovesThatKey(): void
    {
        $services = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(LoggerInterface::class, FileLogger::class, key: 'file')
            ->remove(LoggerInterface::class, 'file')
            ->remove('unknown');

        $this->assertTrue($services->has(LoggerInterface::class));
        $this->assertFalse($services->has(LoggerInterface::class, 'file'));
    }

    public function testHasCoversAliasesOnlyWithoutKey(): void
    {
        $services = (new ServiceCollection())
            ->singleton(FileLogger::class)
            ->alias('logger', FileLogger::class);

        $this->assertTrue($services->has('logger'));
        $this->assertFalse($services->has('logger', 'any'));
        $this->assertFalse($services->has(NullLogger::class));
    }

    public function testAliasCannotPointToItself(): void
    {
        $this->expectException(ContainerException::class);

        (new ServiceCollection())->alias('logger', 'logger');
    }

    public function testTaggingTwiceHasNoEffect(): void
    {
        $container = (new ServiceCollection())
            ->singleton(FileLogger::class)
            ->tag('loggers', FileLogger::class)
            ->tag('loggers', FileLogger::class)
            ->build();

        $this->assertCount(1, $container->tagged('loggers'));
        $this->assertSame([], $container->tagged('unknown'));
    }

    public function testCollectionIsFrozenAfterBuild(): void
    {
        $services = (new ServiceCollection())->singleton(FileLogger::class);
        $services->build();

        $mutations = [
            static fn () => $services->singleton(NullLogger::class),
            static fn () => $services->trySingleton(FileLogger::class),
            static fn () => $services->instance(NullLogger::class, new NullLogger()),
            static fn () => $services->replace(FileLogger::class, FileLogger::class),
            static fn () => $services->remove(FileLogger::class),
            static fn () => $services->alias('a', FileLogger::class),
            static fn () => $services->tag('t', FileLogger::class),
            static fn () => $services->decorate(FileLogger::class, static fn (object $o) => $o),
            static fn () => $services->build(),
        ];

        foreach ($mutations as $i => $mutation) {
            try {
                $mutation();
                $this->fail("Mutation #{$i} did not throw.");
            } catch (ContainerException $e) {
                $this->assertStringContainsString('already built', $e->getMessage());
            }
        }
    }

    public function testFailedBuildLeavesTheCollectionEditable(): void
    {
        $services = (new ServiceCollection())->singleton(LoggerInterface::class);

        try {
            $services->build();
            $this->fail('Build should fail: an interface is not instantiable.');
        } catch (ContainerException) {
        }

        $container = $services->replace(LoggerInterface::class, FileLogger::class)->build();
        $this->assertInstanceOf(FileLogger::class, $container->get(LoggerInterface::class));
    }
}
