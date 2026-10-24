<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Zana\Container\ResolutionContext;

/**
 * Supplies values for parameters and properties that are not services, such as
 * environment variables or configuration entries.
 */
interface ParameterValueProviderInterface
{
    /**
     * Whether this provider supplies the target. Called at build time; must have no side effects.
     */
    public function supports(\ReflectionParameter|\ReflectionProperty $target): bool;

    /**
     * The value for the target. It must match the declared type; a backed enum
     * may be returned as its int or string value.
     */
    public function provide(\ReflectionParameter|\ReflectionProperty $target, ResolutionContext $context): mixed;
}
