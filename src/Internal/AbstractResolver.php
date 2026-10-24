<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\Container;
use Zana\Container\Contracts\Disposable;
use Zana\Container\Contracts\ScopeInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\Exception\DisposalException;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\Scope;
use Zana\Container\ScopeMetadata;

/**
 * Resolution, scope creation and disposal shared by the root container and its scopes.
 *
 * Each resolver caches the instances it owns (singletons for the root,
 * scoped services for a scope), tracks the scopes created from it with weak
 * references, and disposes the owned Disposable instances in reverse creation order.
 *
 * @internal
 */
abstract class AbstractResolver implements ServiceResolverInterface
{
    /** @var array<int, object> Descriptor index => owned cached instance */
    private array $instances = [];

    /** @var list<Disposable> In creation order */
    private array $disposables = [];

    /** @var list<\WeakReference<Scope>> */
    private array $children = [];

    private bool $disposed = false;

    public function __construct(
        protected readonly Registry $registry,
        protected readonly Engine $engine,
        private readonly ScopeMetadata $metadata,
    ) {
    }

    abstract public function root(): Container;

    abstract public function isScope(): bool;

    public function get(string $id): mixed
    {
        $this->assertUsable();

        $index = $this->registry->last($this->registry->resolveAlias($id));
        if ($index === null) {
            throw NotFoundException::forService($id, null, $this->engine->chain());
        }

        return $this->engine->resolve($index, $this);
    }

    public function has(string $id): bool
    {
        $this->assertUsable();

        return $this->registry->isResolvable($id);
    }

    public function getKeyed(string $service, string $key): mixed
    {
        $this->assertUsable();

        $index = $this->registry->last($service, $key);
        if ($index === null) {
            throw NotFoundException::forService($service, $key, $this->engine->chain());
        }

        return $this->engine->resolve($index, $this);
    }

    public function hasKeyed(string $service, string $key): bool
    {
        $this->assertUsable();

        return $this->registry->isRegistered($service, $key);
    }

    public function getAll(string $service): array
    {
        $this->assertUsable();

        return array_map(
            fn (int $index): object => $this->engine->resolve($index, $this),
            $this->registry->all($this->registry->resolveAlias($service))
        );
    }

    public function tagged(string $tag): array
    {
        $this->assertUsable();

        return array_map(
            function (string $service): object {
                $instance = $this->get($service);
                assert(is_object($instance));

                return $instance;
            },
            $this->registry->tagged($tag)
        );
    }

    public function call(callable|array|string $callable, array $arguments = []): mixed
    {
        $this->assertUsable();

        return $this->engine->call($callable, $arguments, $this);
    }

    public function createScope(?ScopeMetadata $metadata = null): ScopeInterface
    {
        $this->assertUsable();

        $this->children = array_values(array_filter(
            $this->children,
            static fn (\WeakReference $reference): bool => $reference->get() !== null
        ));

        $scope = new Scope(
            $this->root(),
            $this,
            $this->registry,
            $this->engine,
            ($metadata ?? new ScopeMetadata('scope'))->completed($this->metadata->id)
        );
        $this->children[] = \WeakReference::create($scope);

        try {
            $this->engine->scopeCreated($scope);
        } catch (\Throwable $e) {
            try {
                $scope->dispose();
            } catch (\Throwable) {
                // the observer failure is the one worth reporting
            }
            throw $e;
        }

        return $scope;
    }

    public function metadata(): ScopeMetadata
    {
        return $this->metadata;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        $errors = $this->disposeChildren();

        if ($this instanceof Scope) {
            array_push($errors, ...$this->engine->scopeDisposing($this));
        }

        foreach (array_reverse($this->disposables) as $service) {
            if (Lazy::isUninitialized($service)) {
                continue; // never used: nothing to release
            }

            try {
                $service->dispose();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        $this->disposables = [];
        $this->instances = [];

        if ($this instanceof Scope) {
            array_push($errors, ...$this->engine->scopeDisposed($this->metadata));
        }

        $this->detach();

        if (count($errors) === 1) {
            throw $errors[0];
        }
        if ($errors !== []) {
            throw DisposalException::fromErrors($errors);
        }
    }

    /**
     * @internal Used by the engine.
     */
    public function cached(int $index): ?object
    {
        return $this->instances[$index] ?? null;
    }

    /**
     * @internal Used by the engine.
     */
    public function store(int $index, object $instance): object
    {
        $this->assertUsable();

        return $this->instances[$index] = $instance;
    }

    /**
     * @internal Used by the engine. Remembers an owned instance for disposal.
     */
    public function track(object $instance): void
    {
        if ($instance instanceof Disposable && !in_array($instance, $this->disposables, true)) {
            $this->disposables[] = $instance;
        }
    }

    /**
     * @internal Called by a child scope when it is disposed.
     */
    public function forget(Scope $scope): void
    {
        $this->children = array_values(array_filter(
            $this->children,
            static fn (\WeakReference $reference): bool => !in_array($reference->get(), [null, $scope], true)
        ));
    }

    /**
     * Unregister from the parent after disposal.
     */
    protected function detach(): void
    {
    }

    protected function assertUsable(): void
    {
        if ($this->disposed) {
            throw ScopeException::disposed($this->isScope() ? 'scope' : 'container');
        }
    }

    /**
     * Dispose the open child scopes, most recent first. Returns the failures.
     *
     * @return list<\Throwable>
     */
    private function disposeChildren(): array
    {
        $errors = [];

        foreach (array_reverse($this->children) as $reference) {
            $scope = $reference->get();
            if ($scope === null || $scope->isDisposed()) {
                continue;
            }

            try {
                $scope->dispose();
            } catch (DisposalException $e) {
                array_push($errors, ...$e->errors());
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        $this->children = [];

        return $errors;
    }
}
