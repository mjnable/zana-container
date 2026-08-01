<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

/**
 * Service with a required scalar parameter that has no default,
 * which cannot be auto-wired and should raise a ResolutionException.
 */
class ServiceWithUnresolvableParam
{
    public function __construct(public readonly string $required)
    {
    }
}

