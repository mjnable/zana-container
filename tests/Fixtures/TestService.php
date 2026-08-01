<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

/**
 * Simple service with no dependencies.
 */
class TestService
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }
}

