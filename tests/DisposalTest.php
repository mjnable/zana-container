<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\DisposalException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\CacheInterface;
use Zana\Container\Tests\Fixtures\DisposableCache;
use Zana\Container\Tests\Fixtures\DisposableCacheDecorator;
use Zana\Container\Tests\Fixtures\DisposableConnection;
use Zana\Container\Tests\Fixtures\DisposableSession;
use Zana\Container\Tests\Fixtures\DisposalLog;
use Zana\Container\Tests\Fixtures\OtherThrowingDisposable;
use Zana\Container\Tests\Fixtures\ThrowingDisposable;

final class DisposalTest extends TestCase
{
    protected function setUp(): void
    {
        DisposalLog::$entries = [];
    }

    public function testScopeDisposesInReverseCreationOrder(): void
    {
        $container = (new ServiceCollection())
            ->scoped(DisposableConnection::class)
            ->scoped(DisposableSession::class)
            ->build();
        $scope = $container->createScope();
        $scope->get(DisposableSession::class);

        $scope->dispose();

        $this->assertSame(['session', 'connection'], DisposalLog::$entries);
    }

    public function testDecoratorsAreDisposedBeforeWhatTheyWrap(): void
    {
        $container = (new ServiceCollection())
            ->singleton(CacheInterface::class, DisposableCache::class)
            ->decorate(CacheInterface::class, DisposableCacheDecorator::class)
            ->build();
        $container->get(CacheInterface::class);

        $container->dispose();

        $this->assertSame(['cache-decorator', 'cache'], DisposalLog::$entries);
    }

    public function testRootDisposesOpenScopesFirst(): void
    {
        $container = (new ServiceCollection())
            ->singleton(DisposableConnection::class)
            ->scoped(CacheInterface::class, DisposableCache::class)
            ->build();
        $container->get(DisposableConnection::class);
        $scope = $container->createScope();
        $scope->get(CacheInterface::class);

        $container->dispose();

        $this->assertTrue($scope->isDisposed());
        $this->assertSame(['cache', 'connection'], DisposalLog::$entries);
    }

    public function testInstancesAndTransientsAreNotDisposed(): void
    {
        $container = (new ServiceCollection())
            ->instance(DisposableConnection::class, new DisposableConnection())
            ->transient(CacheInterface::class, DisposableCache::class)
            ->build();
        $container->get(DisposableConnection::class);
        $container->get(CacheInterface::class);

        $container->dispose();

        $this->assertSame([], DisposalLog::$entries);
    }

    public function testDisposalIsIdempotent(): void
    {
        $container = (new ServiceCollection())->singleton(DisposableConnection::class)->build();
        $container->get(DisposableConnection::class);

        $container->dispose();
        $container->dispose();

        $this->assertSame(['connection'], DisposalLog::$entries);
    }

    public function testDisposedResolversRejectUse(): void
    {
        $container = (new ServiceCollection())->singleton(DisposableConnection::class)->build();
        $scope = $container->createScope();
        $scope->dispose();

        $calls = [
            static fn () => $scope->get(DisposableConnection::class),
            static fn () => $scope->has(DisposableConnection::class),
            static fn () => $scope->getAll(DisposableConnection::class),
            static fn () => $scope->tagged('any'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A disposed scope must reject use.');
            } catch (ScopeException $e) {
                $this->assertStringContainsString('scope has been disposed', $e->getMessage());
            }
        }

        $container->dispose();
        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage('container has been disposed');
        $container->createScope();
    }

    public function testOneFailureIsRethrownUnchangedAfterDisposingTheRest(): void
    {
        $container = (new ServiceCollection())
            ->singleton(DisposableConnection::class)
            ->singleton(ThrowingDisposable::class)
            ->build();
        $container->get(DisposableConnection::class);
        $container->get(ThrowingDisposable::class);

        try {
            $container->dispose();
            $this->fail('Disposal should rethrow.');
        } catch (\LogicException $e) {
            $this->assertSame('cannot close', $e->getMessage());
        }

        $this->assertSame(['throwing', 'connection'], DisposalLog::$entries);
        $this->assertTrue($container->isDisposed());
    }

    public function testSeveralFailuresAreAggregatedAcrossScopes(): void
    {
        $container = (new ServiceCollection())
            ->singleton(ThrowingDisposable::class)
            ->scoped(OtherThrowingDisposable::class)
            ->build();
        $container->get(ThrowingDisposable::class);
        $scope = $container->createScope(); // kept: dropped scopes are not disposed
        $scope->get(OtherThrowingDisposable::class);

        try {
            $container->dispose();
            $this->fail('Disposal should throw.');
        } catch (DisposalException $e) {
            $this->assertCount(2, $e->errors());
            $this->assertInstanceOf(\DomainException::class, $e->getPrevious(), 'the scope was disposed first');
            $this->assertStringContainsString('Disposal failed with 2 errors', $e->getMessage());
        }
    }

    public function testDroppedScopesAreNotKeptAlive(): void
    {
        $container = (new ServiceCollection())->scoped(DisposableConnection::class)->build();

        $scope = $container->createScope();
        $scope->get(DisposableConnection::class);
        $reference = \WeakReference::create($scope);
        unset($scope);

        $this->assertNull($reference->get(), 'the root tracks scopes weakly');

        $container->dispose();
        $this->assertSame([], DisposalLog::$entries, 'a dropped scope is not disposed');
    }
}
