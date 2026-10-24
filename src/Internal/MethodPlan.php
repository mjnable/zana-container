<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * An #[Inject] method called after construction, with its parameter plans.
 *
 * @internal
 */
final readonly class MethodPlan
{
    /**
     * @param list<ParameterPlan> $parameters
     */
    public function __construct(
        public \ReflectionMethod $method,
        public array $parameters,
    ) {
    }
}
