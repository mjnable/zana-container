<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Plans built by build validation.
 *
 * @internal
 */
final readonly class ArrayPlanSource implements PlanSource
{
    /**
     * @param array<class-string, ConstructorPlan> $plans
     * @param array<string, ConstructorPlan> $decoratorPlans
     */
    public function __construct(
        private array $plans,
        private array $decoratorPlans,
    ) {
    }

    public function forClass(string $class): ConstructorPlan
    {
        return $this->plans[$class];
    }

    public function forDecorator(string $service, string $decorator): ConstructorPlan
    {
        return $this->decoratorPlans[BuildResult::decoratorKey($service, $decorator)];
    }
}
