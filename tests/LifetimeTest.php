<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use Zana\Container\Tests\Support\TestServiceCollection;
use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\Helper;
use Zana\Container\Tests\Fixtures\Repository;
use Zana\Container\Tests\Fixtures\UnitOfWork;

final class LifetimeTest extends TestCase
{
    public function testSingletonIsSharedByRootAndEveryScope(): void
    {
        $container = (new TestServiceCollection())->singleton(Clock::class)->build();

        $fromScope = $container->createScope()->get(Clock::class);

        $this->assertSame($fromScope, $container->get(Clock::class));
        $this->assertSame($fromScope, $container->createScope()->get(Clock::class));
    }

    public function testScopedIsSharedWithinAScopeAndIsolatedBetweenScopes(): void
    {
        $container = (new TestServiceCollection())->scoped(UnitOfWork::class)->build();
        $first = $container->createScope();
        $second = $container->createScope();

        $this->assertSame($first->get(UnitOfWork::class), $first->get(UnitOfWork::class));
        $this->assertNotSame($first->get(UnitOfWork::class), $second->get(UnitOfWork::class));
    }

    public function testScopedCannotBeResolvedFromTheRoot(): void
    {
        $container = (new TestServiceCollection())->scoped(UnitOfWork::class)->build();

        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage('cannot be resolved from the root container');
        $container->get(UnitOfWork::class);
    }

    public function testRootStaysUnaffectedAfterUsingAScope(): void
    {
        $container = (new TestServiceCollection())->scoped(UnitOfWork::class)->build();
        $scope = $container->createScope();
        $scope->get(UnitOfWork::class);
        $scope->dispose();

        $this->expectException(ScopeException::class);
        $container->get(UnitOfWork::class);
    }

    public function testTransientIsNewEveryTime(): void
    {
        $container = (new TestServiceCollection())->transient(Clock::class)->build();
        $scope = $container->createScope();

        $this->assertNotSame($container->get(Clock::class), $container->get(Clock::class));
        $this->assertNotSame($scope->get(Clock::class), $scope->get(Clock::class));
    }

    public function testTransientDependenciesComeFromTheRequestingScope(): void
    {
        $container = (new TestServiceCollection())
            ->scoped(UnitOfWork::class)
            ->singleton(Clock::class)
            ->transient(Repository::class)
            ->build();
        $scope = $container->createScope();

        $repository = $scope->get(Repository::class);

        $this->assertSame($scope->get(UnitOfWork::class), $repository->unitOfWork);
        $this->assertSame($container->get(Clock::class), $repository->clock);
    }

    public function testTransientNeedingAScopedServiceFailsFromTheRoot(): void
    {
        $container = (new TestServiceCollection())
            ->scoped(UnitOfWork::class)
            ->transient(Helper::class)
            ->build();

        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage(Helper::class);
        $container->get(Helper::class);
    }

    public function testSingletonFirstResolvedInAScopeTakesItsDependenciesFromTheRoot(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(UnitOfWork::class)
            ->singleton(Helper::class)
            ->build();
        $scope = $container->createScope();

        $helper = $scope->get(Helper::class);
        $scope->dispose();

        $this->assertSame($helper, $container->get(Helper::class));
        $this->assertSame($container->get(UnitOfWork::class), $helper->unitOfWork);
    }

    public function testScopeParentIsTheRoot(): void
    {
        $container = (new TestServiceCollection())->build();

        $this->assertSame($container, $container->createScope()->parent());
    }

    public function testInterleavedFibersDoNotShareResolutionState(): void
    {
        $container = (new TestServiceCollection())
            ->transient('slow', static function (): Clock {
                \Fiber::suspend();

                return new Clock();
            })
            ->transient('fast', static fn (): Clock => new Clock())
            ->build();

        $fiber = new \Fiber(static fn (): mixed => $container->get('slow'));
        $fiber->start();

        // While "slow" is suspended mid-resolution, the main flow resolves freely.
        $this->assertInstanceOf(Clock::class, $container->get('fast'));

        $fiber->resume();
        $this->assertInstanceOf(Clock::class, $fiber->getReturn());
    }
}
