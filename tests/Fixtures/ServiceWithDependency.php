<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

/**
 * Service with a constructor dependency, used to test auto-wiring.
 */
class ServiceWithDependency
{
    public function __construct(
        public readonly DependencyInterface $dependency,
        public readonly TestService $testService,
    ) {
    }
}

