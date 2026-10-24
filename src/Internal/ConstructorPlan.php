<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Precomputed recipe for constructing one class, made at build time.
 *
 * @internal
 */
final readonly class ConstructorPlan
{
    /**
     * @param class-string $class
     * @param list<ParameterPlan> $parameters In declaration order
     * @param list<string> $problems Parameters that cannot be resolved (build error B3)
     * @param int $innerParameters How many parameters receive the decorated instance
     */
    public function __construct(
        public string $class,
        public array $parameters,
        public array $problems = [],
        public int $innerParameters = 0,
    ) {
    }

    /**
     * Arguments must be passed by position when a variadic parameter is present,
     * because named arguments cannot fill it.
     */
    public function isPositional(): bool
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->source === ParameterSource::Variadic) {
                return true;
            }
        }

        return false;
    }

    /**
     * Services this class depends on, for graph validation.
     *
     * @return list<array{ParameterSource, string}> source (Service or Variadic) and service id
     */
    public function dependencies(): array
    {
        $dependencies = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter->service !== null
                && ($parameter->source === ParameterSource::Service || $parameter->source === ParameterSource::Variadic)
            ) {
                $dependencies[] = [$parameter->source, $parameter->service];
            }
        }

        return $dependencies;
    }
}
