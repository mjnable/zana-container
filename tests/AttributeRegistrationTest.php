<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\ContainerException;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceLifetime;
use Zana\Container\Tests\Fixtures\AuditLogger;
use Zana\Container\Tests\Fixtures\FakeMailer;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\NoLifetime;
use Zana\Container\Tests\Fixtures\NotifierInterface;
use Zana\Container\Tests\Fixtures\PushNotifier;
use Zana\Container\Tests\Fixtures\Scan\NotAttributed;
use Zana\Container\Tests\Fixtures\Scan\ScannedLogger;
use Zana\Container\Tests\Fixtures\Scan\Sub\ScannedClock;
use Zana\Container\Tests\Fixtures\Scan\TestingOnly;
use Zana\Container\Tests\Fixtures\TwoLifetimes;

final class AttributeRegistrationTest extends TestCase
{
    private const SCAN_NAMESPACE = 'Zana\\Container\\Tests\\Fixtures\\Scan';

    // ── §5.9 conditions and profiles ───────────────────────────────────────

    public function testWhenRunsTheRegistrationOnlyIfTheConditionHolds(): void
    {
        $services = (new ServiceCollection())
            ->when(true, static fn (ServiceCollection $s) => $s->singleton(FileLogger::class))
            ->when(static fn (): bool => false, static fn (ServiceCollection $s) => $s->singleton(PushNotifier::class));

        $this->assertTrue($services->has(FileLogger::class));
        $this->assertFalse($services->has(PushNotifier::class));
    }

    public function testProfileRunsForActiveProfilesOnly(): void
    {
        $services = (new ServiceCollection(['testing']))
            ->profile('testing', static fn (ServiceCollection $s) => $s->singleton(FileLogger::class))
            ->profile('production', static fn (ServiceCollection $s) => $s->singleton(PushNotifier::class));

        $this->assertSame(['testing'], $services->activeProfiles());
        $this->assertTrue($services->has(FileLogger::class));
        $this->assertFalse($services->has(PushNotifier::class));
    }

    // ── §5.10 attributed registration ──────────────────────────────────────

    public function testAddAttributedReadsLifetimeServiceKeyAndTags(): void
    {
        $services = (new ServiceCollection())->addAttributed(AuditLogger::class, PushNotifier::class);

        [$audit, $push] = $services->descriptors();
        $this->assertSame([LoggerInterface::class, 'audit', ServiceLifetime::Scoped], [$audit->service, $audit->key, $audit->lifetime]);
        $this->assertSame([PushNotifier::class, null, ServiceLifetime::Singleton], [$push->service, $push->key, $push->lifetime]);

        $container = $services->alias(NotifierInterface::class, PushNotifier::class)->build();
        $this->assertSame([$container->get(PushNotifier::class)], $container->tagged('notifiers'));
        $this->assertSame([], $container->tagged('ignored-because-keyed'), 'keyed registrations cannot be tagged');
    }

    public function testProfileAttributeSkipsInactiveClasses(): void
    {
        $this->assertFalse((new ServiceCollection())->addAttributed(FakeMailer::class)->has(FakeMailer::class));
        $this->assertTrue((new ServiceCollection(['ci']))->addAttributed(FakeMailer::class)->has(FakeMailer::class));
    }

    public function testAddAttributedRejectsClassesWithoutExactlyOneLifetime(): void
    {
        foreach ([NoLifetime::class => 'has no #[Singleton]', TwoLifetimes::class => 'more than one lifetime attribute', 'App\\Missing' => 'does not exist'] as $class => $message) {
            try {
                /** @var class-string $class */
                (new ServiceCollection())->addAttributed($class);
                $this->fail("{$class} should be rejected.");
            } catch (ContainerException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    // ── §5.11 scanning ─────────────────────────────────────────────────────

    public function testScanRegistersOnlyAttributedClassesSortedByName(): void
    {
        $services = (new ServiceCollection())->scan(__DIR__ . '/Fixtures/Scan', self::SCAN_NAMESPACE, 'module:Scan');

        $implementations = array_map(static fn ($d) => $d->implementation, $services->descriptors());

        $this->assertSame([ScannedLogger::class, ScannedClock::class], $implementations);
        $this->assertNotContains(NotAttributed::class, $implementations);
        $this->assertNotContains(TestingOnly::class, $implementations, 'inactive profile');
        $this->assertSame('module:Scan', $services->descriptors()[0]->origin);
    }

    public function testScanHonoursProfilesAndTags(): void
    {
        $container = (new ServiceCollection(['testing']))
            ->scan(__DIR__ . '/Fixtures/Scan/', '\\' . self::SCAN_NAMESPACE . '\\')
            ->build();

        $this->assertTrue($container->has(TestingOnly::class));
        $this->assertSame([$container->get(LoggerInterface::class)], $container->tagged('loggers'));
    }

    public function testExplicitRegistrationsWinOverScannedOnes(): void
    {
        $container = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->scan(__DIR__ . '/Fixtures/Scan', self::SCAN_NAMESPACE)
            ->build();

        $this->assertInstanceOf(FileLogger::class, $container->get(LoggerInterface::class));
        $this->assertCount(2, $container->getAll(LoggerInterface::class));
        $this->assertInstanceOf(ScannedLogger::class, $container->getAll(LoggerInterface::class)[0]);
    }

    public function testScanRunsOnlyOnce(): void
    {
        $services = (new ServiceCollection())->scan(__DIR__ . '/Fixtures/Scan', self::SCAN_NAMESPACE);

        $this->assertCount(2, $services->descriptors());
        $this->assertCount(2, $services->descriptors());
    }
}
