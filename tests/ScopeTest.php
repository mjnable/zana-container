<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\DisposalException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ScopeMetadata;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\DisposableConnection;
use Zana\Container\Tests\Fixtures\DisposalLog;
use Zana\Container\Tests\Fixtures\RecordingObserver;
use Zana\Container\Tests\Fixtures\ScopeAware;
use Zana\Container\Tests\Fixtures\UnitOfWork;

final class ScopeTest extends TestCase
{
    protected function setUp(): void
    {
        DisposalLog::$entries = [];
    }

    // ── §8.2 nested scopes ─────────────────────────────────────────────────

    public function testChildScopesOwnTheirScopedInstances(): void
    {
        $container = (new ServiceCollection())->scoped(UnitOfWork::class)->singleton(Clock::class)->build();
        $parent = $container->createScope();
        $child = $parent->createScope();

        $this->assertSame($parent, $child->parent());
        $this->assertNotSame($parent->get(UnitOfWork::class), $child->get(UnitOfWork::class));
        $this->assertSame($child->get(UnitOfWork::class), $child->get(UnitOfWork::class));
        $this->assertSame($container->get(Clock::class), $child->get(Clock::class));
    }

    public function testDisposingAParentDisposesItsChildrenFirst(): void
    {
        $container = (new ServiceCollection())->scoped(DisposableConnection::class)->build();
        $parent = $container->createScope(ScopeMetadata::http());
        $child = $parent->createScope(ScopeMetadata::queue('job'));
        $parent->get(DisposableConnection::class);
        $child->get(DisposableConnection::class);

        $observer = new RecordingObserver();
        unset($observer);

        $parent->dispose();

        $this->assertTrue($child->isDisposed());
        $this->assertSame(['connection', 'connection'], DisposalLog::$entries);
    }

    public function testDisposedResolversCannotCreateScopes(): void
    {
        $container = (new ServiceCollection())->build();
        $scope = $container->createScope();
        $scope->dispose();

        $this->expectException(ScopeException::class);
        $scope->createScope();
    }

    // ── §8.3 metadata ──────────────────────────────────────────────────────

    public function testMetadataIsCompletedByTheContainer(): void
    {
        $container = (new ServiceCollection())->build();
        $request = $container->createScope(ScopeMetadata::http('req-42')->withAttribute('correlation_id', 'abc'));
        $job = $request->createScope();

        $this->assertSame(['root', null], [$container->metadata()->type, $container->metadata()->parentId]);
        $this->assertSame(['http', 'req-42', $container->metadata()->id], [$request->metadata()->type, $request->metadata()->id, $request->metadata()->parentId]);
        $this->assertSame('abc', $request->metadata()->attribute('correlation_id'));
        $this->assertNull($request->metadata()->attribute('tenant_id'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $request->metadata()->createdAt);
        $this->assertSame(['scope', $request->metadata()->id], [$job->metadata()->type, $job->metadata()->parentId]);
        $this->assertSame(16, strlen($job->metadata()->id));
    }

    public function testStaticConstructorsSetTypeAndName(): void
    {
        $this->assertSame(['cli', 'migrate'], [ScopeMetadata::cli('migrate')->type, ScopeMetadata::cli('migrate')->name]);
        $this->assertSame(['queue', 'send-mail'], [ScopeMetadata::queue('send-mail')->type, ScopeMetadata::queue('send-mail')->name]);
        $this->assertSame(['test', 'it works'], [ScopeMetadata::test('it works')->type, ScopeMetadata::test('it works')->name]);
    }

    public function testScopeMetadataCanBeInjected(): void
    {
        $container = (new ServiceCollection())
            ->scoped('scoped-aware', ScopeAware::class)
            ->singleton('singleton-aware', ScopeAware::class)
            ->build();
        $scope = $container->createScope(ScopeMetadata::http());

        $this->assertSame($scope->metadata(), $scope->get('scoped-aware')->scope);
        $this->assertSame($container->metadata(), $scope->get('singleton-aware')->scope, 'singletons see the root metadata');
    }

    // ── §8.4 observers ─────────────────────────────────────────────────────

    public function testObserversSeeTheScopeLifecycle(): void
    {
        $observer = new RecordingObserver();
        $container = (new ServiceCollection())->addScopeObserver($observer)->scoped(DisposableConnection::class)->build();

        $scope = $container->createScope(ScopeMetadata::cli());
        $scope->get(DisposableConnection::class);
        $scope->dispose();
        $container->dispose();

        $this->assertSame(['created:cli', 'disposing:cli', 'disposed:cli'], $observer->events);
        $this->assertSame(['connection'], DisposalLog::$entries);
    }

    public function testAFailingCreatedObserverDisposesTheScope(): void
    {
        $container = (new ServiceCollection())->addScopeObserver(new RecordingObserver(failOnCreate: true))->build();

        try {
            $container->createScope();
            $this->fail('createScope() should rethrow.');
        } catch (\RuntimeException $e) {
            $this->assertSame('observer refused the scope', $e->getMessage());
        }
    }

    public function testDisposingObserverFailuresAreCollected(): void
    {
        $container = (new ServiceCollection())
            ->addScopeObserver(new RecordingObserver(failOnDisposing: true))
            ->addScopeObserver(new RecordingObserver(failOnDisposing: true))
            ->scoped(DisposableConnection::class)
            ->build();
        $scope = $container->createScope();
        $scope->get(DisposableConnection::class);

        try {
            $scope->dispose();
            $this->fail('dispose() should throw.');
        } catch (DisposalException $e) {
            $this->assertCount(2, $e->errors());
        }

        $this->assertSame(['connection'], DisposalLog::$entries, 'services are still disposed');
    }
}
