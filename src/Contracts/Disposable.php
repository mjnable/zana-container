<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * Optional contract for services that need cleanup when their scope ends.
 * 
 * Particularly useful for long-running runtimes (RoadRunner, FrankenPHP, Swoole)
 * where resources like database connections, file handles, or streams need
 * explicit release when a request or job scope is disposed.
 */
interface Disposable
{
    /**
     * Release resources held by this service.
     * Called automatically when the owning scope is disposed.
     */
    public function dispose(): void;
}

