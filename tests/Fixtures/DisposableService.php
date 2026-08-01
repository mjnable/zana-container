<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures;

use Zana\Container\Contracts\Disposable;

class DisposableService implements Disposable
{
    public bool $disposed = false;

    public function dispose(): void
    {
        $this->disposed = true;
    }
}

