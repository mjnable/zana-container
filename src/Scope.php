<?php

declare(strict_types=1);

namespace Zana\Container;

use Zana\Container\Contracts\ScopeInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\Internal\AbstractResolver;
use Zana\Container\Internal\Engine;
use Zana\Container\Internal\Registry;

/**
 * A unit of work owning its scoped service instances.
 *
 * Singletons still come from the root container; scoped services are created
 * and cached here (a child scope never reuses its parent's); transients get
 * their dependencies from this scope.
 *
 * @internal Type-hint Contracts\ScopeInterface instead.
 */
final class Scope extends AbstractResolver implements ScopeInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly AbstractResolver $parentResolver,
        Registry $registry,
        Engine $engine,
        ScopeMetadata $metadata,
    ) {
        parent::__construct($registry, $engine, $metadata);
    }

    public function root(): Container
    {
        return $this->container;
    }

    public function isScope(): bool
    {
        return true;
    }

    public function parent(): ServiceResolverInterface
    {
        return $this->parentResolver;
    }

    protected function detach(): void
    {
        $this->parentResolver->forget($this);
    }
}
