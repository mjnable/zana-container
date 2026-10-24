<?php

declare(strict_types=1);

/**
 * Test fixtures. Grouped in one file (loaded through autoload-dev "files")
 * because most are one-liners.
 */

namespace Zana\Container\Tests\Fixtures;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Zana\Container\Contracts\Disposable;
use Zana\Container\Contracts\ServiceResolverInterface;

// ── Loggers and decorators ─────────────────────────────────────────────────

interface LoggerInterface
{
    public function log(string $message): string;
}

class FileLogger implements LoggerInterface
{
    public function log(string $message): string
    {
        return "file:{$message}";
    }
}

class NullLogger implements LoggerInterface
{
    public function log(string $message): string
    {
        return '';
    }
}

class PrefixLogger implements LoggerInterface
{
    public function __construct(public readonly LoggerInterface $inner, public readonly ?Clock $clock = null)
    {
    }

    public function log(string $message): string
    {
        return 'prefix:' . $this->inner->log($message);
    }
}

class UpperLogger implements LoggerInterface
{
    public function __construct(public readonly LoggerInterface $inner)
    {
    }

    public function log(string $message): string
    {
        return strtoupper($this->inner->log($message));
    }
}

class DecoratorWithoutInner implements LoggerInterface
{
    public function log(string $message): string
    {
        return $message;
    }
}

class DecoratorWithTwoInners implements LoggerInterface
{
    public function __construct(public readonly LoggerInterface $first, public readonly LoggerInterface $second)
    {
    }

    public function log(string $message): string
    {
        return $message;
    }
}

class NotALogger
{
    public function __construct(public readonly LoggerInterface $inner)
    {
    }
}

class DecoratorNeedingUnregistered implements LoggerInterface
{
    public function __construct(public readonly LoggerInterface $inner, public readonly UnregisteredInterface $missing)
    {
    }

    public function log(string $message): string
    {
        return $message;
    }
}

// ── Plain services ─────────────────────────────────────────────────────────

class Clock
{
}

class Mailer
{
    public function __construct(public readonly LoggerInterface $logger)
    {
    }
}

interface UnregisteredInterface
{
}

class WithDefaults
{
    /**
     * @param array<string, int> $options
     */
    public function __construct(
        public readonly int $retries = 3,
        public readonly ?LoggerInterface $logger = null,
        public readonly array $options = ['level' => 1],
        public readonly Clock $clock = new Clock(),
    ) {
    }
}

class WithNullable
{
    public function __construct(public readonly ?UnregisteredInterface $optional)
    {
    }
}

class NeedsUnregistered
{
    public function __construct(public readonly UnregisteredInterface $missing)
    {
    }
}

class NeedsScalar
{
    public function __construct(public readonly string $dsn)
    {
    }
}

class NeedsUntyped
{
    public function __construct(public readonly mixed $thing = 'default', public $untyped = null)
    {
    }
}

class NeedsUntypedWithoutDefault
{
    public function __construct(public $thing)
    {
    }
}

class NeedsUnion
{
    public function __construct(public readonly FileLogger|NullLogger $logger)
    {
    }
}

class NeedsResolvers
{
    public function __construct(
        public readonly ServiceResolverInterface $resolver,
        public readonly PsrContainerInterface $psr,
    ) {
    }
}

class ThrowsInConstructor
{
    public function __construct()
    {
        throw new \RuntimeException('database is down');
    }
}

abstract class AbstractService
{
}

class PrivateConstructor
{
    private function __construct()
    {
    }
}

enum Color: string
{
    case Red = 'red';
}

trait SomeTrait
{
}

class UsesSomeTrait
{
    use SomeTrait;
}

// ── Notifiers (multiple implementations, variadics) ────────────────────────

interface NotifierInterface
{
}

class EmailNotifier implements NotifierInterface
{
}

class SmsNotifier implements NotifierInterface
{
}

class NotifierHub
{
    /** @var list<NotifierInterface> */
    public readonly array $notifiers;

    public function __construct(public readonly LoggerInterface $logger, public readonly int $retries = 2, NotifierInterface ...$notifiers)
    {
        $this->notifiers = array_values($notifiers);
    }
}

class ScalarVariadic
{
    /** @var list<int> */
    public readonly array $ids;

    public function __construct(int ...$ids)
    {
        $this->ids = array_values($ids);
    }
}

// ── Cycles ─────────────────────────────────────────────────────────────────

class CycleA
{
    public function __construct(public readonly CycleB $b)
    {
    }
}

class CycleB
{
    public function __construct(public readonly CycleA $a)
    {
    }
}

class SelfDependent
{
    public function __construct(public readonly self $self)
    {
    }
}

// ── Lifetimes and captive dependencies ─────────────────────────────────────

class UnitOfWork
{
}

class Helper
{
    public function __construct(public readonly UnitOfWork $unitOfWork)
    {
    }
}

class ReportCache
{
    public function __construct(public readonly Helper $helper)
    {
    }
}

class Repository
{
    public function __construct(public readonly UnitOfWork $unitOfWork, public readonly Clock $clock)
    {
    }
}

// ── Disposal ───────────────────────────────────────────────────────────────

final class DisposalLog
{
    /** @var list<string> */
    public static array $entries = [];
}

class DisposableConnection implements Disposable
{
    public function dispose(): void
    {
        DisposalLog::$entries[] = 'connection';
    }
}

class DisposableSession implements Disposable
{
    public function __construct(public readonly DisposableConnection $connection)
    {
    }

    public function dispose(): void
    {
        DisposalLog::$entries[] = 'session';
    }
}

class ThrowingDisposable implements Disposable
{
    public function dispose(): void
    {
        DisposalLog::$entries[] = 'throwing';
        throw new \LogicException('cannot close');
    }
}

class OtherThrowingDisposable implements Disposable
{
    public function dispose(): void
    {
        DisposalLog::$entries[] = 'other-throwing';
        throw new \DomainException('cannot flush');
    }
}

interface CacheInterface
{
}

class DisposableCache implements CacheInterface, Disposable
{
    public function dispose(): void
    {
        DisposalLog::$entries[] = 'cache';
    }
}

class DisposableCacheDecorator implements CacheInterface, Disposable
{
    public function __construct(public readonly CacheInterface $inner)
    {
    }

    public function dispose(): void
    {
        DisposalLog::$entries[] = 'cache-decorator';
    }
}
