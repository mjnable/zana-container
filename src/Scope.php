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
 * and cached here; transients get their dependencies from this scope.
 *
 * @internal Type-hint Contracts\ScopeInterface instead.
 */
final class Scope extends AbstractResolver implements ScopeInterface
{
    public function __construct(
        private readonly Container $container,
        Registry $registry,
        Engine $engine,
    ) {
        parent::__construct($registry, $engine);
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
        return $this->container;
    }

    protected function detach(): void
    {
        $this->container->forget($this);
    }
}
