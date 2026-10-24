<?php

declare(strict_types=1);

/**
 * Fixtures for attributes, value providers, union types and injection.
 */

namespace Zana\Container\Tests\Fixtures;

use Attribute;
use Zana\Container\Attribute\Env;
use Zana\Container\Attribute\Inject;
use Zana\Container\Attribute\Profile;
use Zana\Container\Attribute\Scoped;
use Zana\Container\Attribute\Singleton;
use Zana\Container\Attribute\Tagged;
use Zana\Container\Attribute\Transient;
use Zana\Container\Attribute\ValueAttribute;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\ResolutionContext;

// ── Lifetime attributes ────────────────────────────────────────────────────

#[Scoped(service: LoggerInterface::class, key: 'audit', tags: ['ignored-because-keyed'])]
class AuditLogger implements LoggerInterface
{
    public function log(string $message): string
    {
        return "audit:{$message}";
    }
}

#[Singleton(tags: ['notifiers'])]
class PushNotifier implements NotifierInterface
{
}

#[Transient]
#[Profile('testing', 'ci')]
class FakeMailer
{
}

#[Transient]
#[Singleton]
class TwoLifetimes
{
}

class NoLifetime
{
}

// ── Union, intersection and DNF types ──────────────────────────────────────

interface Readable
{
}

interface Writable
{
}

class Stream implements Readable, Writable
{
}

class ReadOnlyStream implements Readable
{
}

class NeedsStream
{
    public function __construct(public readonly Readable&Writable $stream)
    {
    }
}

class NeedsDnf
{
    public function __construct(public readonly (Readable&Writable)|Clock $value)
    {
    }
}

class NeedsOptionalUnion
{
    public function __construct(public readonly FileLogger|NullLogger|null $logger)
    {
    }
}

// ── #[Inject] and #[Tagged] parameters ─────────────────────────────────────

class UsesInjectedParameters
{
    /**
     * @param iterable<NotifierInterface> $notifiers
     */
    public function __construct(
        #[Inject(key: 'audit')] public readonly LoggerInterface $audit,
        #[Inject('primary-logger')] public readonly object $primary,
        #[Tagged('notifiers')] public readonly iterable $notifiers,
    ) {
    }
}

class InjectsMissing
{
    public function __construct(#[Inject('missing')] public readonly object $missing)
    {
    }
}

class InjectsMissingKey
{
    public function __construct(#[Inject(key: 'nope')] public readonly LoggerInterface $logger)
    {
    }
}

class InjectsWithoutType
{
    public function __construct(#[Inject] public readonly mixed $value)
    {
    }
}

class TaggedWrongType
{
    public function __construct(#[Tagged('notifiers')] public readonly string $notifiers)
    {
    }
}

// ── Value providers ────────────────────────────────────────────────────────

enum Level: int
{
    case Low = 1;
    case High = 2;
}

class EnvSettings
{
    public function __construct(
        #[Env('ZANA_TEST_PORT')] public readonly int $port,
        #[Env('ZANA_TEST_DEBUG')] public readonly bool $debug = false,
        #[Env('ZANA_TEST_COLOR')] public readonly ?Color $color = null,
        #[Env('ZANA_TEST_RATIO')] public readonly float $ratio = 1.0,
        #[Env('ZANA_TEST_NAME')] public readonly string $name = 'zana',
    ) {
    }
}

class EnvRequired
{
    public function __construct(#[Env('ZANA_TEST_UNSET')] public readonly string $value)
    {
    }
}

class EnvLevel
{
    public function __construct(#[Env('ZANA_TEST_LEVEL')] public readonly Level $level)
    {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Setting implements ValueAttribute
{
    public function __construct(public readonly string $key)
    {
    }
}

/**
 * Supplies #[Setting] values from an array, recording the contexts it saw.
 */
final class ArraySettingsProvider implements ParameterValueProviderInterface
{
    /** @var list<ResolutionContext> */
    public array $contexts = [];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(private readonly array $settings)
    {
    }

    public function supports(\ReflectionParameter|\ReflectionProperty $target): bool
    {
        return $target->getAttributes(Setting::class) !== [];
    }

    public function provide(\ReflectionParameter|\ReflectionProperty $target, ResolutionContext $context): mixed
    {
        $this->contexts[] = $context;

        return $this->settings[$target->getAttributes(Setting::class)[0]->newInstance()->key] ?? null;
    }
}

class UsesSettings
{
    public function __construct(
        #[Setting('mode')] public readonly string $mode,
        #[Setting('level')] public readonly Level $level,
    ) {
    }
}

class SettingWithoutProvider
{
    public function __construct(#[Setting('mode')] public readonly string $mode)
    {
    }
}

// ── Property and method injection ──────────────────────────────────────────

class WithInjectedProperties
{
    #[Inject]
    public LoggerInterface $logger;

    #[Inject]
    private Clock $clock;

    /** @var list<object> */
    #[Tagged('notifiers')]
    public array $notifiers = [];

    #[Setting('mode')]
    public string $mode = 'unset';

    #[Inject]
    public ?UnregisteredInterface $optional;

    #[Inject]
    public ?NullLogger $keepsDefault = null;

    public function clock(): Clock
    {
        return $this->clock;
    }
}

class ReadonlyInjected
{
    #[Inject]
    public readonly Clock $clock;
}

class StaticInjected
{
    #[Inject]
    public static ?Clock $clock = null;
}

class UntypedInjected
{
    #[Inject]
    public $thing;
}

class WithInjectedMethods
{
    /** @var list<string> */
    public array $calls = [];

    public ?LoggerInterface $logger = null;

    #[Inject]
    public function useLogger(LoggerInterface $logger): void
    {
        $this->calls[] = 'logger';
        $this->logger = $logger;
    }

    #[Inject]
    protected function useClock(Clock $clock, int $retries = 2): void
    {
        $this->calls[] = "clock:{$retries}";
    }
}

class StaticInjectMethod
{
    #[Inject]
    public static function configure(Clock $clock): void
    {
    }
}

// ── call() targets ─────────────────────────────────────────────────────────

class ReportController
{
    public function show(LoggerInterface $logger, int $id, string $format = 'html'): string
    {
        return $logger->log("report {$id} as {$format}");
    }

    public static function today(Clock $clock): Clock
    {
        return $clock;
    }

    public function fail(): never
    {
        throw new \DomainException('report not found');
    }

    public function needsUnregistered(UnregisteredInterface $missing): void
    {
    }
}

class InvokableAction
{
    public function __invoke(Clock $clock, string $name = 'action'): string
    {
        return $name;
    }
}

function zana_test_function(Clock $clock): Clock
{
    return $clock;
}
