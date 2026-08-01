<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

/**
 * Service whose constructor uses scalar defaults so the container
 * can auto-wire it without explicit bindings.
 */
class ServiceWithDefaults
{
    public function __construct(
        public readonly string $name = 'default-name',
        public readonly int $limit = 10,
    ) {
    }
}

