<?php

declare(strict_types=1);

namespace Zana\Container;

use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ScopeInterface;
use Zana\Container\Internal\AbstractResolver;

/**
 * The root container produced by ServiceCollection::build().
 *
 * Owns singletons. Tracks the scopes created from it with weak references,
 * so a scope that is dropped without being disposed can still be collected.
 *
 * @internal Type-hint Contracts\ContainerInterface instead.
 */
final class Container extends AbstractResolver implements ContainerInterface
{
    /** @var list<\WeakReference<Scope>> */
    private array $scopes = [];

    public function root(): Container
    {
        return $this;
    }

    public function isScope(): bool
    {
        return false;
    }

    public function createScope(): ScopeInterface
    {
        $this->assertUsable();

        $this->scopes = array_values(array_filter(
            $this->scopes,
            static fn (\WeakReference $reference): bool => $reference->get() !== null
        ));

        $scope = new Scope($this, $this->registry, $this->engine);
        $this->scopes[] = \WeakReference::create($scope);

        return $scope;
    }

    /**
     * @internal Called by a scope when it is disposed.
     */
    public function forget(Scope $scope): void
    {
        $this->scopes = array_values(array_filter(
            $this->scopes,
            static fn (\WeakReference $reference): bool => !in_array($reference->get(), [null, $scope], true)
        ));
    }

    /**
     * Open scopes are disposed before the singletons, most recent first.
     */
    protected function disposeChildren(): array
    {
        $errors = [];

        foreach (array_reverse($this->scopes) as $reference) {
            $scope = $reference->get();
            if ($scope === null || $scope->isDisposed()) {
                continue;
            }

            try {
                $scope->dispose();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        $this->scopes = [];

        return self::flatten($errors);
    }
}
