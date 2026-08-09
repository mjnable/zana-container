<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

use Zana\Contracts\Container\DisposableInterface;

class DisposableService implements DisposableInterface
{
    public bool $disposed = false;

    public function dispose(): void
    {
        $this->disposed = true;
    }
}
