<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Container\BuildError;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\AbstractService;
use Zana\Container\Tests\Fixtures\ArraySettingsProvider;
use Zana\Container\Tests\Fixtures\EnvRequired;
use Zana\Container\Tests\Fixtures\InjectsMissing;
use Zana\Container\Tests\Fixtures\InjectsMissingKey;
use Zana\Container\Tests\Fixtures\InjectsWithoutType;
use Zana\Container\Tests\Fixtures\NeedsStream;
use Zana\Container\Tests\Fixtures\Readable;
use Zana\Container\Tests\Fixtures\ReadonlyInjected;
use Zana\Container\Tests\Fixtures\SettingWithoutProvider;
use Zana\Container\Tests\Fixtures\StaticInjected;
use Zana\Container\Tests\Fixtures\StaticInjectMethod;
use Zana\Container\Tests\Fixtures\Stream;
use Zana\Container\Tests\Fixtures\TaggedWrongType;
use Zana\Container\Tests\Fixtures\UntypedInjected;
use Zana\Container\Tests\Fixtures\WithInjectedMethods;
use Zana\Container\Tests\Fixtures\WithInjectedProperties;
use Zana\Container\Tests\Fixtures\Writable;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\Color;
use Zana\Container\Tests\Fixtures\CycleA;
use Zana\Container\Tests\Fixtures\CycleB;
use Zana\Container\Tests\Fixtures\DecoratorNeedingUnregistered;
use Zana\Container\Tests\Fixtures\DecoratorWithoutInner;
use Zana\Container\Tests\Fixtures\DecoratorWithTwoInners;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\Helper;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\Mailer;
use Zana\Container\Tests\Fixtures\NeedsScalar;
use Zana\Container\Tests\Fixtures\NeedsUnion;
use Zana\Container\Tests\Fixtures\NeedsUnregistered;
use Zana\Container\Tests\Fixtures\NeedsUntypedWithoutDefault;
use Zana\Container\Tests\Fixtures\NotALogger;
use Zana\Container\Tests\Fixtures\NullLogger;
use Zana\Container\Tests\Fixtures\PrefixLogger;
use Zana\Container\Tests\Fixtures\PrivateConstructor;
use Zana\Container\Tests\Fixtures\ReportCache;
use Zana\Container\Tests\Fixtures\ScalarVariadic;
use Zana\Container\Tests\Fixtures\SelfDependent;
use Zana\Container\Tests\Fixtures\SomeTrait;
use Zana\Container\Tests\Fixtures\UnitOfWork;

final class BuildValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(ServiceCollection): mixed, string, string}>
     */
    public static function invalidRegistrations(): iterable
    {
        // B1 — the implementation must be instantiable
        yield 'B1 missing class' => [static fn (ServiceCollection $s) => $s->singleton('svc', 'App\\Missing'), 'B1', 'class App\\Missing does not exist'];
        yield 'B1 interface' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class), 'B1', 'is an interface'];
        yield 'B1 abstract' => [static fn (ServiceCollection $s) => $s->singleton(AbstractService::class), 'B1', 'is abstract'];
        yield 'B1 enum' => [static fn (ServiceCollection $s) => $s->singleton(Color::class), 'B1', 'is an enum'];
        yield 'B1 trait' => [static fn (ServiceCollection $s) => $s->singleton(SomeTrait::class), 'B1', 'is a trait'];
        yield 'B1 private constructor' => [static fn (ServiceCollection $s) => $s->singleton(PrivateConstructor::class), 'B1', 'non-public constructor'];

        // B2 — the implementation must satisfy the service type
        yield 'B2 class' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, Clock::class), 'B2', Clock::class . ' does not implement or extend ' . LoggerInterface::class];
        yield 'B2 instance' => [static fn (ServiceCollection $s) => $s->instance(LoggerInterface::class, new Clock()), 'B2', 'the registered instance is a ' . Clock::class];

        // B3 — every parameter must resolve
        yield 'B3 unregistered' => [static fn (ServiceCollection $s) => $s->transient(NeedsUnregistered::class), 'B3', 'UnregisteredInterface is not registered'];
        yield 'B3 scalar' => [static fn (ServiceCollection $s) => $s->transient(NeedsScalar::class), 'B3', 'parameter $dsn of type string needs a default value'];
        yield 'B3 untyped' => [static fn (ServiceCollection $s) => $s->transient(NeedsUntypedWithoutDefault::class), 'B3', '$thing has no type'];
        yield 'B3 union without candidates' => [static fn (ServiceCollection $s) => $s->transient(NeedsUnion::class), 'B3', 'no registered service satisfies'];
        yield 'B10 ambiguous union' => [static fn (ServiceCollection $s) => $s->singleton(FileLogger::class)->singleton(NullLogger::class)->transient(NeedsUnion::class), 'B10', 'is ambiguous'];
        yield 'B3 scalar variadic' => [static fn (ServiceCollection $s) => $s->transient(ScalarVariadic::class), 'B3', 'variadic but has no class type'];

        // B4 — no constructor cycles
        yield 'B4 two classes' => [static fn (ServiceCollection $s) => $s->transient(CycleA::class)->transient(CycleB::class), 'B4', CycleA::class . ' → ' . CycleB::class . ' → ' . CycleA::class];
        yield 'B4 self' => [static fn (ServiceCollection $s) => $s->transient(SelfDependent::class), 'B4', SelfDependent::class . ' → ' . SelfDependent::class];

        // B5 — captive dependencies
        yield 'B5 direct' => [static fn (ServiceCollection $s) => $s->scoped(UnitOfWork::class)->singleton(Helper::class), 'B5', Helper::class . ' (singleton) → ' . UnitOfWork::class . ' (scoped)'];
        yield 'B5 through transients' => [
            static fn (ServiceCollection $s) => $s->scoped(UnitOfWork::class)->transient(Helper::class)->singleton(ReportCache::class, origin: 'package:reporting'),
            'B5',
            ReportCache::class . ' (singleton, package:reporting) → ' . Helper::class . ' (transient) → ' . UnitOfWork::class . ' (scoped): a singleton must not depend on a scoped service',
        ];
        yield 'B5 through a decorator' => [
            static fn (ServiceCollection $s) => $s->scoped(Clock::class)->singleton(LoggerInterface::class, FileLogger::class)->decorate(LoggerInterface::class, PrefixLogger::class),
            'B5',
            Clock::class . ' (scoped)',
        ];

        // B6 — aliases
        yield 'B6 missing target' => [static fn (ServiceCollection $s) => $s->alias('logger', LoggerInterface::class), 'B6', 'points to ' . LoggerInterface::class . ', which is not registered'];
        yield 'B6 cycle' => [static fn (ServiceCollection $s) => $s->alias('a', 'b')->alias('b', 'a'), 'B6', 'aliases form a cycle'];
        yield 'B6 alias is a service' => [static fn (ServiceCollection $s) => $s->singleton(FileLogger::class)->singleton(NullLogger::class)->alias(NullLogger::class, FileLogger::class), 'B6', 'is also registered as a service'];

        // B7 — tags
        yield 'B7 unregistered member' => [static fn (ServiceCollection $s) => $s->tag('loggers', FileLogger::class), 'B7', "tag 'loggers' contains " . FileLogger::class];

        // B8 — decorators
        yield 'B8 unregistered service' => [static fn (ServiceCollection $s) => $s->decorate(LoggerInterface::class, PrefixLogger::class), 'B8', 'decorated service ' . LoggerInterface::class . ' is not registered'];
        yield 'B8 alias' => [static fn (ServiceCollection $s) => $s->singleton(FileLogger::class)->alias('logger', FileLogger::class)->decorate('logger', static fn (object $o) => $o), 'B8', 'it is an alias, so decorate ' . FileLogger::class];
        yield 'B8 no inner parameter' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, FileLogger::class)->decorate(LoggerInterface::class, DecoratorWithoutInner::class), 'B8', 'found 0'];
        yield 'B8 two inner parameters' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, FileLogger::class)->decorate(LoggerInterface::class, DecoratorWithTwoInners::class), 'B8', 'found 2'];
        yield 'B8 wrong type' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, FileLogger::class)->decorate(LoggerInterface::class, NotALogger::class), 'B8', 'it does not implement or extend'];
        yield 'B8 string id' => [static fn (ServiceCollection $s) => $s->singleton('logger', FileLogger::class)->decorate('logger', PrefixLogger::class), 'B8', 'use a closure decorator for logger'];
        // B9 — #[Inject] targets
        yield 'B9 missing id' => [static fn (ServiceCollection $s) => $s->transient(InjectsMissing::class), 'B9', '#[Inject] target missing is not registered'];
        yield 'B9 missing key' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, FileLogger::class)->transient(InjectsMissingKey::class), 'B9', '[key: nope] is not registered'];
        yield 'B9 no type' => [static fn (ServiceCollection $s) => $s->transient(InjectsWithoutType::class), 'B9', 'has #[Inject] without an id and has no class type'];

        // B10 — ambiguous intersection
        yield 'B10 intersection' => [static fn (ServiceCollection $s) => $s->singleton(Readable::class, Stream::class)->singleton(Writable::class, Stream::class)->transient(NeedsStream::class), 'B10', 'Readable, ' ];

        // B11 — attribute misuse
        yield 'B11 tagged type' => [static fn (ServiceCollection $s) => $s->transient(TaggedWrongType::class), 'B11', 'has #[Tagged] but is not typed array or iterable'];
        yield 'B11 value without provider' => [static fn (ServiceCollection $s) => $s->transient(SettingWithoutProvider::class), 'B11', 'has #[Setting] but no registered parameter value provider supports it'];
        yield 'B11 env without provider' => [static fn (ServiceCollection $s) => $s->transient(EnvRequired::class), 'B11', 'has #[Env]'];
        yield 'B11 readonly property' => [static fn (ServiceCollection $s) => $s->singleton(Clock::class)->transient(ReadonlyInjected::class), 'B11', 'property $clock is readonly'];
        yield 'B11 static property' => [static fn (ServiceCollection $s) => $s->singleton(Clock::class)->transient(StaticInjected::class), 'B11', 'property $clock is static'];
        yield 'B11 untyped property' => [static fn (ServiceCollection $s) => $s->transient(UntypedInjected::class), 'B11', 'property $thing has no type'];
        yield 'B11 static method' => [static fn (ServiceCollection $s) => $s->singleton(Clock::class)->transient(StaticInjectMethod::class), 'B11', 'method configure() is static'];
        yield 'B3 method parameter' => [static fn (ServiceCollection $s) => $s->transient(WithInjectedMethods::class), 'B3', 'method useLogger() parameter $logger'];
        yield 'B11 two lifetimes in a scan' => [static fn (ServiceCollection $s) => $s->scan(__DIR__ . '/Fixtures/ScanBroken', 'Zana\\Container\\Tests\\Fixtures\\ScanBroken'), 'B11', 'more than one lifetime attribute'];

        // B13 — scanning
        yield 'B13 missing directory' => [static fn (ServiceCollection $s) => $s->scan(__DIR__ . '/Nope', 'App'), 'B13', 'does not exist'];
        yield 'B13 file without its class' => [static fn (ServiceCollection $s) => $s->scan(__DIR__ . '/Fixtures/ScanBroken', 'Zana\\Container\\Tests\\Fixtures\\ScanBroken'), 'B13', 'does not declare the autoloadable class Zana\\Container\\Tests\\Fixtures\\ScanBroken\\Mismatch'];

        // B4 and B5 see property and method edges
        yield 'B5 through an injected property' => [static fn (ServiceCollection $s) => $s->scoped(Clock::class)->singleton(LoggerInterface::class, FileLogger::class)->addParameterProvider(new ArraySettingsProvider([]))->singleton(WithInjectedProperties::class), 'B5', Clock::class . ' (scoped)'];

        yield 'B3 decorator parameter' => [static fn (ServiceCollection $s) => $s->singleton(LoggerInterface::class, FileLogger::class)->decorate(LoggerInterface::class, DecoratorNeedingUnregistered::class), 'B3', 'decorator ' . DecoratorNeedingUnregistered::class . ': constructor parameter $missing'];
    }

    /**
     * @param \Closure(ServiceCollection): mixed $register
     */
    #[DataProvider('invalidRegistrations')]
    public function testRuleIsEnforced(\Closure $register, string $code, string $message): void
    {
        $services = new ServiceCollection();
        $register($services);

        $errors = $this->buildErrors($services);
        $matching = array_filter($errors, static fn (BuildError $e): bool => $e->code === $code && str_contains($e->message, $message));

        $this->assertNotEmpty($matching, "Expected [{$code}] containing \"{$message}\", got:\n" . implode("\n", array_map('strval', $errors)));
    }

    public function testValidGraphBuilds(): void
    {
        $container = (new ServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(Mailer::class)
            ->scoped(UnitOfWork::class)
            ->scoped(Helper::class)
            ->transient(ReportCache::class)
            ->alias('logger', LoggerInterface::class)
            ->tag('loggers', 'logger')
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build();

        $this->assertInstanceOf(Mailer::class, $container->get(Mailer::class));
    }

    public function testFactoriesAreOpaqueToValidation(): void
    {
        $container = (new ServiceCollection())
            ->scoped(UnitOfWork::class)
            ->singleton('captive', static fn ($r) => $r->get(UnitOfWork::class))
            ->build();

        $this->assertTrue($container->has('captive'), 'B3-B5 cannot see inside factories');
    }

    public function testEveryErrorIsReportedAtOnce(): void
    {
        $services = (new ServiceCollection())
            ->singleton(LoggerInterface::class, origin: 'module:Core')
            ->transient(NeedsScalar::class)
            ->tag('loggers', 'missing');

        try {
            $services->build();
            $this->fail('Build should fail.');
        } catch (ContainerBuildException $e) {
            $this->assertSame(['B1', 'B3', 'B7'], array_map(static fn (BuildError $error): string => $error->code, $e->errors()));
            $this->assertStringStartsWith('Container build failed with 3 errors:', $e->getMessage());
            $this->assertStringContainsString('[B1] ' . LoggerInterface::class . ' (module:Core): ', $e->getMessage());

            $first = $e->errors()[0];
            $this->assertSame([LoggerInterface::class, null, 'module:Core'], [$first->service, $first->key, $first->origin]);
        }
    }

    /**
     * @return list<BuildError>
     */
    private function buildErrors(ServiceCollection $services): array
    {
        try {
            $services->build();
        } catch (ContainerBuildException $e) {
            return $e->errors();
        }

        return [];
    }
}
