<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

/**
 * Interface to demonstrate interface-to-implementation binding.
 */
interface DependencyInterface
{
    public function getValue(): string;
}

