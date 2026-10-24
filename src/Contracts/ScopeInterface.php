<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * A unit of work (HTTP request, CLI command, job, test) owning its scoped services.
 */
interface ScopeInterface extends ServiceResolverInterface
{
    /**
     * The resolver this scope was created from.
     */
    public function parent(): ServiceResolverInterface;
}
