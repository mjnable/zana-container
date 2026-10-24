<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Plans loaded from a compiled container. Each plan is decoded on first use,
 * so only the classes actually resolved are reflected.
 *
 * @internal
 *
 * @phpstan-import-type PlanData from PlanCodec
 */
final class CompiledPlanSource implements PlanSource
{
    /** @var array<string, ConstructorPlan> */
    private array $decoded = [];

    /**
     * @param array<string, PlanData> $plans Implementation class => plan data
     * @param array<string, PlanData> $decoratorPlans "service|decorator" => plan data
     */
    public function __construct(
        private readonly array $plans,
        private readonly array $decoratorPlans,
    ) {
    }

    public function forClass(string $class): ConstructorPlan
    {
        return $this->decoded["class:{$class}"] ??= PlanCodec::decode($this->plans[$class]);
    }

    public function forDecorator(string $service, string $decorator): ConstructorPlan
    {
        $key = BuildResult::decoratorKey($service, $decorator);

        return $this->decoded["decorator:{$key}"] ??= PlanCodec::decode($this->decoratorPlans[$key]);
    }
}
