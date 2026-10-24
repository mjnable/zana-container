<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Zana\Container\ServiceLifetime;

/**
 * Base of the lifetime attributes #[Singleton], #[Scoped] and #[Transient].
 *
 * @internal Use one of the concrete attributes.
 */
abstract class RegistrationAttribute
{
    /**
     * @param string|null $service Service id to register under; defaults to the class itself
     * @param string|null $key Key of a keyed registration
     * @param list<string> $tags Tags to add the service to
     * @param bool $lazy Resolve as a proxy created on first use
     */
    public function __construct(
        public readonly ?string $service = null,
        public readonly ?string $key = null,
        public readonly array $tags = [],
        public readonly bool $lazy = false,
    ) {
    }

    abstract public function lifetime(): ServiceLifetime;
}
