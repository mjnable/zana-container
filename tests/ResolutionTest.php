<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use Zana\Container\Tests\Support\TestServiceCollection;
use PHPUnit\Framework\TestCase;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\Exception\CircularDependencyException;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\EmailNotifier;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\Mailer;
use Zana\Container\Tests\Fixtures\NeedsResolvers;
use Zana\Container\Tests\Fixtures\NeedsUntyped;
use Zana\Container\Tests\Fixtures\NotifierHub;
use Zana\Container\Tests\Fixtures\NotifierInterface;
use Zana\Container\Tests\Fixtures\NullLogger;
use Zana\Container\Tests\Fixtures\PrefixLogger;
use Zana\Container\Tests\Fixtures\SmsNotifier;
use Zana\Container\Tests\Fixtures\ThrowsInConstructor;
use Zana\Container\Tests\Fixtures\UpperLogger;
use Zana\Container\Tests\Fixtures\WithDefaults;
use Zana\Container\Tests\Fixtures\WithNullable;

final class ResolutionTest extends TestCase
{
    // ── §6.1 registered services only ──────────────────────────────────────

    public function testUnregisteredClassesAreNeverCreated(): void
    {
        $container = (new TestServiceCollection())->build();

        $this->assertFalse($container->has(FileLogger::class));

        $this->expectException(NotFoundException::class);
        $container->get(FileLogger::class);
    }

    public function testLastRegistrationWins(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(LoggerInterface::class, NullLogger::class)
            ->build();

        $this->assertInstanceOf(NullLogger::class, $container->get(LoggerInterface::class));
    }

    public function testGetAllReturnsEveryRegistrationInOrder(): void
    {
        $email = new EmailNotifier();
        $container = (new TestServiceCollection())
            ->transient(NotifierInterface::class, SmsNotifier::class)
            ->instance(NotifierInterface::class, $email)
            ->transient(NotifierInterface::class, SmsNotifier::class, key: 'keyed')
            ->build();

        $all = $container->getAll(NotifierInterface::class);

        $this->assertCount(2, $all, 'keyed registrations are not included');
        $this->assertInstanceOf(SmsNotifier::class, $all[0]);
        $this->assertSame($email, $all[1]);
        $this->assertSame([], $container->getAll('nothing-registered'));
    }

    // ── §5.2 keys ──────────────────────────────────────────────────────────

    public function testKeyedAndUnkeyedRegistrationsAreSeparate(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(LoggerInterface::class, NullLogger::class, key: 'null')
            ->build();

        $this->assertInstanceOf(FileLogger::class, $container->get(LoggerInterface::class));
        $this->assertInstanceOf(NullLogger::class, $container->getKeyed(LoggerInterface::class, 'null'));
        $this->assertTrue($container->hasKeyed(LoggerInterface::class, 'null'));
        $this->assertFalse($container->hasKeyed(LoggerInterface::class, 'file'));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('[key: file]');
        $container->getKeyed(LoggerInterface::class, 'file');
    }

    // ── §5.3 aliases, §5.4 tags ────────────────────────────────────────────

    public function testAliasChainsResolveLikeTheirTarget(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(FileLogger::class)
            ->alias(LoggerInterface::class, FileLogger::class)
            ->alias('logger', LoggerInterface::class)
            ->build();

        $this->assertTrue($container->has('logger'));
        $this->assertSame($container->get(FileLogger::class), $container->get('logger'));
        $this->assertSame([$container->get(FileLogger::class)], $container->getAll('logger'));
    }

    public function testTaggedResolvesMembersInTaggingOrder(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(FileLogger::class)
            ->singleton(NullLogger::class)
            ->alias('file', FileLogger::class)
            ->tag('loggers', NullLogger::class, 'file')
            ->build();

        $this->assertSame(
            [$container->get(NullLogger::class), $container->get(FileLogger::class)],
            $container->tagged('loggers')
        );
    }

    // ── §6.2 parameter resolution ──────────────────────────────────────────

    public function testRegisteredClassAndInterfaceParametersAreResolved(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(Mailer::class)
            ->build();

        $this->assertSame($container->get(LoggerInterface::class), $container->get(Mailer::class)->logger);
    }

    public function testResolverParametersReceiveTheOwningResolver(): void
    {
        $container = (new TestServiceCollection())->transient(NeedsResolvers::class)->build();
        $scope = $container->createScope();

        $fromRoot = $container->get(NeedsResolvers::class);
        $fromScope = $scope->get(NeedsResolvers::class);

        $this->assertSame($container, $fromRoot->resolver);
        $this->assertSame($container, $fromRoot->psr);
        $this->assertSame($scope, $fromScope->resolver, 'a transient is owned by its requester');
    }

    public function testDefaultsAreUsedWhenNothingIsRegistered(): void
    {
        $container = (new TestServiceCollection())->transient(WithDefaults::class)->build();

        $first = $container->get(WithDefaults::class);
        $second = $container->get(WithDefaults::class);

        $this->assertSame([3, null, ['level' => 1]], [$first->retries, $first->logger, $first->options]);
        $this->assertNotSame($first->clock, $second->clock, 'new-expression defaults are evaluated each time');
    }

    public function testRegistrationsTakePrecedenceOverDefaults(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->singleton(Clock::class)
            ->transient(WithDefaults::class)
            ->build();

        $service = $container->get(WithDefaults::class);

        $this->assertInstanceOf(FileLogger::class, $service->logger);
        $this->assertSame($container->get(Clock::class), $service->clock);
    }

    public function testNullableParametersWithoutDefaultReceiveNull(): void
    {
        $container = (new TestServiceCollection())->transient(WithNullable::class)->build();

        $this->assertNull($container->get(WithNullable::class)->optional);
    }

    public function testUntypedAndMixedParametersUseTheirDefaults(): void
    {
        $container = (new TestServiceCollection())->transient(NeedsUntyped::class)->build();

        $service = $container->get(NeedsUntyped::class);
        $this->assertSame(['default', null], [$service->thing, $service->untyped]);
    }

    public function testVariadicsReceiveEveryRegistrationAndDefaultsStayPositional(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(NotifierInterface::class, EmailNotifier::class)
            ->transient(NotifierInterface::class, SmsNotifier::class)
            ->transient(NotifierHub::class)
            ->build();

        $hub = $container->get(NotifierHub::class);

        $this->assertSame(2, $hub->retries);
        $this->assertCount(2, $hub->notifiers);
        $this->assertInstanceOf(EmailNotifier::class, $hub->notifiers[0]);
        $this->assertInstanceOf(SmsNotifier::class, $hub->notifiers[1]);
    }

    public function testEmptyVariadicIsAllowed(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(NotifierHub::class)
            ->build();

        $this->assertSame([], $container->get(NotifierHub::class)->notifiers);
    }

    // ── §6.5 factories ─────────────────────────────────────────────────────

    public function testFactoriesReceiveTheOwningResolver(): void
    {
        $received = [];
        $container = (new TestServiceCollection())
            ->singleton(Clock::class, static function (ServiceResolverInterface $r) use (&$received): Clock {
                $received[] = $r;

                return new Clock();
            })
            ->transient(FileLogger::class, static function (ServiceResolverInterface $r) use (&$received): FileLogger {
                $received[] = $r;

                return new FileLogger();
            })
            ->build();
        $scope = $container->createScope();

        $scope->get(Clock::class);
        $scope->get(FileLogger::class);

        $this->assertSame([$container, $scope], $received);
    }

    public function testFactoryResultMustMatchTheService(): void
    {
        $container = (new TestServiceCollection())
            ->transient(LoggerInterface::class, static fn (): Clock => new Clock())
            ->transient('anything', static fn (): Clock => new Clock())
            ->build();

        $this->assertInstanceOf(Clock::class, $container->get('anything'), 'string ids carry no type');

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('must return an instance of ' . LoggerInterface::class . '; got ' . Clock::class);
        $container->get(LoggerInterface::class);
    }

    public function testFactoryMustReturnAnObject(): void
    {
        $container = (new TestServiceCollection())->transient('number', static fn (): int => 42)->build();

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('got int');
        $container->get('number');
    }

    // ── §6.6 cycles at runtime ─────────────────────────────────────────────

    public function testCycleThroughFactoriesIsDetectedAtRuntime(): void
    {
        $container = (new TestServiceCollection())
            ->singleton('a', static fn (ServiceResolverInterface $r): object => $r->get('b'), origin: 'module:A')
            ->singleton('b', static fn (ServiceResolverInterface $r): object => $r->get('a'))
            ->build();

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('a (module:A) → b → a (module:A)');
        $container->get('a');
    }

    public function testResolutionStackIsCleanAfterFailure(): void
    {
        $calls = new \ArrayObject();
        $container = (new TestServiceCollection())
            ->transient('flaky', static function () use ($calls): Clock {
                $calls->append(true);
                if (count($calls) === 1) {
                    throw new \RuntimeException('first call fails');
                }

                return new Clock();
            })
            ->build();

        try {
            $container->get('flaky');
        } catch (ResolutionException) {
        }

        $this->assertInstanceOf(Clock::class, $container->get('flaky'));
    }

    // ── §5.8 decorators ────────────────────────────────────────────────────

    public function testDecoratorsApplyInRegistrationOrder(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->decorate(LoggerInterface::class, static fn (LoggerInterface $inner): LoggerInterface => new UpperLogger($inner))
            ->build();

        $logger = $container->get(LoggerInterface::class);

        $this->assertSame('PREFIX:FILE:HI', $logger->log('hi'));
        $this->assertSame($logger, $container->get(LoggerInterface::class), 'the decorated result keeps the singleton lifetime');
    }

    public function testDecoratorsApplyToEveryRegistrationIncludingKeyed(): void
    {
        $container = (new TestServiceCollection())
            ->transient(LoggerInterface::class, FileLogger::class)
            ->transient(LoggerInterface::class, NullLogger::class)
            ->transient(LoggerInterface::class, FileLogger::class, key: 'file')
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build();

        foreach ([...$container->getAll(LoggerInterface::class), $container->getKeyed(LoggerInterface::class, 'file')] as $logger) {
            $this->assertInstanceOf(PrefixLogger::class, $logger);
        }
    }

    public function testClassDecoratorsResolveTheirOtherParameters(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Clock::class)
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build();

        $logger = $container->get(LoggerInterface::class);
        $this->assertInstanceOf(PrefixLogger::class, $logger);
        $this->assertSame($container->get(Clock::class), $logger->clock);
    }

    public function testClosureDecoratorResultMustMatchTheService(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->decorate(LoggerInterface::class, static fn (): Clock => new Clock())
            ->build();

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('A closure decorator of ' . LoggerInterface::class . ' must return an instance of');
        $container->get(LoggerInterface::class);
    }

    public function testInstanceRegistrationsCanBeDecorated(): void
    {
        $inner = new FileLogger();
        $container = (new TestServiceCollection())
            ->instance(LoggerInterface::class, $inner)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build();

        $logger = $container->get(LoggerInterface::class);
        $this->assertInstanceOf(PrefixLogger::class, $logger);
        $this->assertSame($inner, $logger->inner);
        $this->assertSame($logger, $container->get(LoggerInterface::class));
    }

    // ── §10 errors ─────────────────────────────────────────────────────────

    public function testConstructorFailuresAreWrappedWithTheChain(): void
    {
        $container = (new TestServiceCollection())
            ->transient(ThrowsInConstructor::class, origin: 'module:Db')
            ->transient('report', static fn (ServiceResolverInterface $r): object => $r->get(ThrowsInConstructor::class), origin: 'module:Reports')
            ->build();

        try {
            $container->get('report');
            $this->fail('Expected a ResolutionException.');
        } catch (ResolutionException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString('database is down', $e->getMessage());
            $this->assertStringContainsString("report (module:Reports)\n  → " . ThrowsInConstructor::class . ' (module:Db)', $e->getMessage());
        }
    }

    public function testNotFoundInsideResolutionReportsTheChain(): void
    {
        $container = (new TestServiceCollection())
            ->transient('report', static fn (ServiceResolverInterface $r): object => $r->get('missing'), origin: 'module:Reports')
            ->build();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Service not registered: missing.\nResolution chain:\n  report (module:Reports)");
        $container->get('report');
    }
}
