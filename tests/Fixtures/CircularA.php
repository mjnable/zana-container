<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

class CircularA
{
    public function __construct(public readonly CircularB $b)
    {
    }
}

