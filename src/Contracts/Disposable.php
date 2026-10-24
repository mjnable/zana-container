<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * A service that releases resources when its owning scope or container is disposed.
 */
interface Disposable
{
    public function dispose(): void;
}
