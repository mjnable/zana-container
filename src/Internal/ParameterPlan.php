<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * How one parameter or injected property is resolved.
 *
 * @internal
 */
final readonly class ParameterPlan
{
    public function __construct(
        public ParameterSource $source,
        public \ReflectionParameter|\ReflectionProperty $target,
        public ?string $service = null,
        public ?string $key = null,
        public ?string $tag = null,
        public ?int $provider = null,
    ) {
    }

    public function name(): string
    {
        return $this->target->getName();
    }

    /**
     * Arguments must be passed by position when a variadic parameter is present,
     * because named arguments cannot fill it.
     *
     * @param list<ParameterPlan> $plans
     */
    public static function needPositional(array $plans): bool
    {
        foreach ($plans as $plan) {
            if ($plan->source === ParameterSource::Variadic) {
                return true;
            }
        }

        return false;
    }
}
