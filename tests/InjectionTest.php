<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\ArraySettingsProvider;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\EmailNotifier;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\InvokableAction;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\NullLogger;
use Zana\Container\Tests\Fixtures\ReportController;
use Zana\Container\Tests\Fixtures\WithInjectedMethods;
use Zana\Container\Tests\Fixtures\WithInjectedProperties;

use function Zana\Container\Tests\Fixtures\zana_test_function;

final class InjectionTest extends TestCase
{
    // ── §7.1 properties ────────────────────────────────────────────────────

    public function testPropertiesAreInjectedAfterConstruction(): void
    {
        $container = (new ServiceCollection())
            ->addParameterProvider(new ArraySettingsProvider(['mode' => 'strict']))
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(Clock::class)
            ->singleton(EmailNotifier::class)
            ->tag('notifiers', EmailNotifier::class)
            ->transient(WithInjectedProperties::class)
            ->build();

        $service = $container->get(WithInjectedProperties::class);

        $this->assertSame($container->get(LoggerInterface::class), $service->logger);
        $this->assertSame($container->get(Clock::class), $service->clock(), 'private properties are injected too');
        $this->assertSame([$container->get(EmailNotifier::class)], $service->notifiers);
        $this->assertSame('strict', $service->mode);
        $this->assertNull($service->optional);
        $this->assertNull($service->keepsDefault, 'an unregistered type keeps the declared default');
    }

    public function testRegisteredPropertyTypesOverrideTheDefault(): void
    {
        $container = (new ServiceCollection())
            ->addParameterProvider(new ArraySettingsProvider(['mode' => 'lenient']))
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(Clock::class)
            ->singleton(NullLogger::class)
            ->transient(WithInjectedProperties::class)
            ->build();

        $this->assertSame($container->get(NullLogger::class), $container->get(WithInjectedProperties::class)->keepsDefault);
    }

    // ── §7.2 methods ───────────────────────────────────────────────────────

    public function testInjectMethodsRunInDeclarationOrder(): void
    {
        $container = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(Clock::class)
            ->transient(WithInjectedMethods::class)
            ->build();

        $service = $container->get(WithInjectedMethods::class);

        $this->assertSame(['logger', 'clock:2'], $service->calls);
        $this->assertSame($container->get(LoggerInterface::class), $service->logger);
    }

    // ── §7.3 call() ────────────────────────────────────────────────────────

    public function testCallResolvesParametersAndAcceptsNamedOverrides(): void
    {
        $container = $this->container();

        $this->assertSame('file:report 7 as pdf', $container->call([new ReportController(), 'show'], ['id' => 7, 'format' => 'pdf']));
        $this->assertSame('file:report 7 as html', $container->call([ReportController::class, 'show'], ['id' => 7]), 'class methods resolve the class from the container');
    }

    public function testCallSupportsEveryCallableForm(): void
    {
        $container = $this->container();
        $clock = $container->get(Clock::class);

        $this->assertSame($clock, $container->call(static fn (Clock $c): Clock => $c));
        $this->assertSame($clock, $container->call(ReportController::class . '::today'));
        $this->assertSame($clock, $container->call([ReportController::class, 'today']));
        $this->assertSame('named', $container->call(new InvokableAction(), ['name' => 'named']));
        $this->assertSame($clock, $container->call('Zana\\Container\\Tests\\Fixtures\\zana_test_function'));
        $this->assertSame($clock, zana_test_function($clock));
    }

    public function testCallUsesTheResolverItIsCalledOn(): void
    {
        $container = $this->container();
        $scope = $container->createScope();

        $this->assertSame($scope, $scope->call(static fn (\Psr\Container\ContainerInterface $c): object => $c));
    }

    public function testCallRejectsPositionalArguments(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('named arguments only');

        /** @phpstan-ignore argument.type (positional arguments are rejected at runtime; that is under test) */
        $this->container()->call([ReportController::class, 'show'], [7]);
    }

    public function testCallReportsUnresolvableParameters(): void
    {
        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('Cannot call ' . ReportController::class . "::show():\n  [B3] parameter \$id of type int");

        $this->container()->call([ReportController::class, 'show']);
    }

    public function testCallDoesNotWrapTheCallablesOwnExceptions(): void
    {
        $this->expectException(\DomainException::class);

        $this->container()->call([ReportController::class, 'fail']);
    }

    public function testCallOnAnUnregisteredClassIsNotFound(): void
    {
        $container = (new ServiceCollection())->build();

        $this->expectException(NotFoundException::class);
        $container->call([ReportController::class, 'show'], ['id' => 1]);
    }

    public function testCallRejectsInvalidCallables(): void
    {
        $this->expectException(ContainerException::class);

        /** @phpstan-ignore argument.type (invalid on purpose) */
        $this->container()->call([1, 2, 3]);
    }

    private function container(): \Zana\Container\Contracts\ContainerInterface
    {
        return (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(Clock::class)
            ->transient(ReportController::class)
            ->build();
    }
}
