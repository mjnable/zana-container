<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use Zana\Container\Tests\Support\TestServiceCollection;
use PHPUnit\Framework\TestCase;
use Zana\Container\Attribute\Singleton;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;
use Zana\Container\Tests\Fixtures\ChickenService;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\DisposalLog;
use Zana\Container\Tests\Fixtures\EggService;
use Zana\Container\Tests\Fixtures\ExpensiveService;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LazyArrayObject;
use Zana\Container\Tests\Fixtures\LazyDisposable;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\PrefixLogger;
use Zana\Container\Tests\Fixtures\RecordingInterceptor;
use Zana\Container\Tests\Fixtures\Stateless;
use Zana\Container\Tests\Fixtures\ReplacingInterceptor;
use Zana\Container\Tests\Fixtures\ThrowingInterceptor;

final class InterceptionAndLazyTest extends TestCase
{
    protected function setUp(): void
    {
        ExpensiveService::$constructed = 0;
        DisposalLog::$entries = [];
    }

    // ── §9.1 interceptors ──────────────────────────────────────────────────

    public function testInterceptorsWrapCreationOutermostFirstAndSkipCacheHits(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $container = (new TestServiceCollection())
            ->addInterceptor(new RecordingInterceptor('outer', $log))
            ->addInterceptor(new RecordingInterceptor('inner', $log))
            ->singleton(Clock::class)
            ->build();

        $container->createScope()->get(Clock::class);
        $container->get(Clock::class);

        $root = 'root';
        $this->assertSame(["outer>" . Clock::class . "@{$root}", 'inner>' . Clock::class . "@{$root}", 'inner<', 'outer<'], $log->getArrayCopy());
    }

    public function testInterceptorsSkipInstanceRegistrationsAndRunBeforeDecorators(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $container = (new TestServiceCollection())
            ->addInterceptor(new RecordingInterceptor('i', $log))
            ->instance(Clock::class, new Clock())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build();

        $container->get(Clock::class);
        $logger = $container->get(LoggerInterface::class);

        $this->assertInstanceOf(PrefixLogger::class, $logger);
        $this->assertSame(['i>' . LoggerInterface::class . '@root', 'i<'], $log->getArrayCopy(), 'decorators are not intercepted separately');
    }

    public function testInterceptorResultMustSatisfyTheService(): void
    {
        $container = (new TestServiceCollection())
            ->addInterceptor(new ReplacingInterceptor())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->build();

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('Interceptor ' . ReplacingInterceptor::class . ' for ' . LoggerInterface::class . ' must return an instance of');
        $container->get(LoggerInterface::class);
    }

    public function testInterceptorFailuresAreWrapped(): void
    {
        $container = (new TestServiceCollection())->addInterceptor(new ThrowingInterceptor())->singleton(Clock::class)->build();

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('interceptor exploded');
        $container->get(Clock::class);
    }

    // ── §9.2 lazy services ─────────────────────────────────────────────────

    public function testLazyServicesAreCreatedOnFirstUseOnly(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Clock::class)
            ->singleton(ExpensiveService::class, lazy: true)
            ->build();

        $service = $container->get(ExpensiveService::class);
        $this->assertSame(0, ExpensiveService::$constructed);
        $this->assertSame($service, $container->get(ExpensiveService::class), 'one proxy per singleton');

        $this->assertSame('expensive:' . Clock::class, $service->value(), 'touching state initialises the proxy');
        $service->value();
        $this->assertSame(1, ExpensiveService::$constructed);
        $this->assertSame($container->get(Clock::class), $service->clock);
    }

    public function testLazyTransientsGiveOneProxyPerResolution(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Clock::class)
            ->transient(ExpensiveService::class, lazy: true)
            ->build();

        $first = $container->get(ExpensiveService::class);
        $second = $container->get(ExpensiveService::class);
        $first->value();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, ExpensiveService::$constructed);
    }

    public function testLazyServicesBreakConstructorCycles(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(ChickenService::class)
            ->singleton(EggService::class, lazy: true)
            ->build();

        $chicken = $container->get(ChickenService::class);

        $this->assertSame($chicken, $chicken->egg->chicken);
    }

    public function testUninitialisedLazyServicesAreNotDisposed(): void
    {
        $container = (new TestServiceCollection())
            ->singleton('used', LazyDisposable::class, lazy: true)
            ->singleton('unused', LazyDisposable::class, lazy: true)
            ->build();

        $used = $container->get('used');
        $this->assertInstanceOf(LazyDisposable::class, $used);
        $used->touch();
        $container->get('unused');

        $container->dispose();

        $this->assertSame(['lazy'], DisposalLog::$entries);
    }

    public function testLazyAttributeIsRead(): void
    {
        $class = new #[Singleton(lazy: true)] class {
            public int $state = 0;
        };

        $services = (new TestServiceCollection())->addAttributed($class::class);

        $this->assertTrue($services->descriptors()[0]->lazy);
    }

    public function testOnlyClassRegistrationsCanBeLazy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceDescriptor('svc', ServiceLifetime::Singleton, factory: static fn () => new Clock(), lazy: true);
    }

    public function testInternalClassesCannotBeLazy(): void
    {
        $services = (new TestServiceCollection())->singleton(LazyArrayObject::class, lazy: true);

        $this->expectExceptionMessage('[B12] ' . LazyArrayObject::class . ': cannot be lazy: PHP cannot make internal classes');
        $services->build();
    }

    public function testClassesWithoutStateCannotBeLazy(): void
    {
        $services = (new TestServiceCollection())->singleton(Stateless::class, lazy: true);

        $this->expectExceptionMessage('its constructor would never run');
        $services->build();
    }
}
