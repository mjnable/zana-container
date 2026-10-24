<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Contracts\CapabilitiesInterface;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\NullLogger;

final class CapabilitiesTest extends TestCase
{
    public function testCapabilitiesAreQueryableThroughAnInjectableService(): void
    {
        $container = (new ServiceCollection())
            ->provide('reporting', 'package:reporting')
            ->provide('reporting', 'module:Finance')
            ->provide('reporting', 'module:Finance')
            ->provide('audit')
            ->build();

        $capabilities = $container->get(CapabilitiesInterface::class);

        $this->assertTrue($capabilities->has('reporting'));
        $this->assertFalse($capabilities->has('search'));
        $this->assertSame(['audit', 'reporting'], $capabilities->all());
        $this->assertSame(['package:reporting', 'module:Finance'], $capabilities->providers('reporting'));
        $this->assertSame(['(unknown)'], $capabilities->providers('audit'));
        $this->assertSame([], $capabilities->providers('search'));
    }

    public function testWhenCapabilityRunsAtBuildAfterOtherRegistrations(): void
    {
        $container = (new ServiceCollection())
            ->whenCapability('reporting', static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, NullLogger::class))
            ->whenCapability('search', static fn (ServiceCollection $s) => $s->singleton(Clock::class))
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->provide('reporting')
            ->build();

        $this->assertInstanceOf(NullLogger::class, $container->get(LoggerInterface::class), 'callback registrations come last');
        $this->assertFalse($container->has(Clock::class));
    }

    public function testCapabilityCallsAreForbiddenInsideCallbacks(): void
    {
        $services = (new ServiceCollection())
            ->provide('reporting')
            ->whenCapability('reporting', static fn (ServiceCollection $s) => $s->provide('search'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('provide() cannot be called inside a whenCapability() callback');
        $services->build();
    }

    public function testCapabilitiesCanBeDeclaredInsideImmediateCallbacks(): void
    {
        $container = (new ServiceCollection(['testing']))
            ->profile('testing', static fn (ServiceCollection $s) => $s->provide('fakes'))
            ->build();

        $this->assertTrue($container->get(CapabilitiesInterface::class)->has('fakes'));
    }

    public function testNamesAreValidated(): void
    {
        foreach (['Reporting', '1st', '', 'with space'] as $name) {
            try {
                (new ServiceCollection())->provide($name);
                $this->fail("'{$name}' should be rejected.");
            } catch (ContainerException $e) {
                $this->assertStringContainsString('Invalid capability name', $e->getMessage());
            }
        }

        $this->assertTrue((new ServiceCollection())->provide('reporting.pdf-v2')->build()->has(CapabilitiesInterface::class));
    }

    public function testRequirementsAndTheReservedServiceAreValidated(): void
    {
        $services = (new ServiceCollection())
            ->requires('reporting', 'module:Finance')
            ->requires('audit')
            ->provide('audit')
            ->singleton(CapabilitiesInterface::class, static fn () => throw new \LogicException());

        try {
            $services->build();
            $this->fail('Build should fail.');
        } catch (ContainerBuildException $e) {
            $messages = array_map('strval', $e->errors());
            $this->assertSame([
                "[B14] capability 'reporting' is required by module:Finance but nothing provides it",
                '[B14] ' . CapabilitiesInterface::class . ' is registered by the container itself and cannot be registered explicitly',
            ], $messages);
        }
    }
}
