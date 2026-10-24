<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * The root container produced by ServiceCollection::build().
 */
interface ContainerInterface extends ServiceResolverInterface
{
    /**
     * Create a scope that owns its own scoped service instances.
     */
    public function createScope(): ScopeInterface;
}
