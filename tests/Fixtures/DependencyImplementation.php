<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

class DependencyImplementation implements DependencyInterface
{
    public function getValue(): string
    {
        return 'dependency-implementation';
    }
}

