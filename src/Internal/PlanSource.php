<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Where the engine gets construction plans: built at build time, or loaded
 * from a compiled container.
 *
 * @internal
 */
interface PlanSource
{
    /**
     * @param class-string $class
     */
    public function forClass(string $class): ConstructorPlan;

    public function forDecorator(string $service, string $decorator): ConstructorPlan;
}
