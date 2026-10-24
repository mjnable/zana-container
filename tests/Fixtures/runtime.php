<?php

declare(strict_types=1);

/**
 * Fixtures for scopes, observers, interceptors and lazy services.
 */

namespace Zana\Container\Tests\Fixtures;

use Zana\Container\Contracts\Disposable;
use Zana\Container\Contracts\ResolutionInterceptorInterface;
use Zana\Container\Contracts\ScopeInterface;
use Zana\Container\Contracts\ScopeObserverInterface;
use Zana\Container\ResolutionContext;
use Zana\Container\ScopeMetadata;

final class RecordingObserver implements ScopeObserverInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly bool $failOnCreate = false,
        private readonly bool $failOnDisposing = false,
    ) {
    }

    public function scopeCreated(ScopeInterface $scope): void
    {
        $this->events[] = "created:{$scope->metadata()->type}";
        if ($this->failOnCreate) {
            throw new \RuntimeException('observer refused the scope');
        }
    }

    public function scopeDisposing(ScopeInterface $scope): void
    {
        $this->events[] = "disposing:{$scope->metadata()->type}";
        if ($this->failOnDisposing) {
            throw new \RuntimeException('observer failed while disposing');
        }
    }

    public function scopeDisposed(ScopeMetadata $metadata): void
    {
        $this->events[] = "disposed:{$metadata->type}";
    }
}

final class RecordingInterceptor implements ResolutionInterceptorInterface
{
    /**
     * @param \ArrayObject<int, string> $log
     */
    public function __construct(private readonly string $name, private readonly \ArrayObject $log)
    {
    }

    public function intercept(ResolutionContext $context, \Closure $next): object
    {
        $this->log->append("{$this->name}>{$context->descriptor?->service}@{$context->scope->type}");
        $result = $next();
        $this->log->append("{$this->name}<");

        return $result;
    }
}

final class ReplacingInterceptor implements ResolutionInterceptorInterface
{
    public function intercept(ResolutionContext $context, \Closure $next): object
    {
        return new Clock();
    }
}

final class ThrowingInterceptor implements ResolutionInterceptorInterface
{
    public function intercept(ResolutionContext $context, \Closure $next): object
    {
        throw new \RuntimeException('interceptor exploded');
    }
}

class ExpensiveService
{
    public static int $constructed = 0;

    public function __construct(public readonly Clock $clock)
    {
        self::$constructed++;
    }

    public function value(): string
    {
        return 'expensive:' . $this->clock::class;
    }
}

class LazyDisposable implements Disposable
{
    private string $state = 'ready';

    public function touch(): string
    {
        return $this->state;
    }

    public function dispose(): void
    {
        DisposalLog::$entries[] = 'lazy';
    }
}

class ChickenService
{
    public function __construct(public readonly EggService $egg)
    {
    }
}

class EggService
{
    public function __construct(public readonly ChickenService $chicken)
    {
    }
}

class ScopeAware
{
    public function __construct(public readonly ScopeMetadata $scope)
    {
    }
}

/**
 * @extends \ArrayObject<int, mixed>
 */
class LazyArrayObject extends \ArrayObject
{
}

class Stateless
{
}
