<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when a requested service is not registered.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $chain
     */
    public static function forService(string $service, ?string $key = null, array $chain = []): self
    {
        $what = $key === null ? $service : "{$service} [key: {$key}]";

        return new self("Service not registered: {$what}." . self::formatChain($chain));
    }
}
