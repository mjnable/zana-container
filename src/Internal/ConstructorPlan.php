<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Precomputed recipe for creating one class, made at build time: constructor
 * arguments, then injected properties, then #[Inject] methods.
 *
 * @internal
 */
final readonly class ConstructorPlan
{
    /**
     * @param class-string $class
     * @param list<ParameterPlan> $parameters Constructor parameters in declaration order
     * @param list<ParameterPlan> $properties Injected properties in declaration order
     * @param list<MethodPlan> $methods #[Inject] methods in declaration order
     * @param list<array{string, string}> $problems Rule code and message of each unresolvable target
     * @param int $innerParameters How many parameters receive the decorated instance
     */
    public function __construct(
        public string $class,
        public array $parameters,
        public array $properties = [],
        public array $methods = [],
        public array $problems = [],
        public int $innerParameters = 0,
    ) {
    }

    /**
     * Every plan that pulls another registration, for graph validation.
     *
     * @return list<ParameterPlan>
     */
    public function dependencies(): array
    {
        $plans = [...$this->parameters, ...$this->properties];
        foreach ($this->methods as $method) {
            array_push($plans, ...$method->parameters);
        }

        return array_values(array_filter(
            $plans,
            static fn (ParameterPlan $plan): bool => in_array(
                $plan->source,
                [ParameterSource::Service, ParameterSource::Keyed, ParameterSource::Tagged, ParameterSource::Variadic],
                true
            )
        ));
    }
}
