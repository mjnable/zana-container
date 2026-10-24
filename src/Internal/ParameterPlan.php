<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * How one constructor parameter is resolved.
 *
 * @internal
 */
final readonly class ParameterPlan
{
    public function __construct(
        public ParameterSource $source,
        public \ReflectionParameter $parameter,
        public ?string $service = null,
    ) {
    }
}
