<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when a service cannot be found or resolved.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public static function forService(string $service, ?string $chain = null): self
    {
        $message = "Service not found: {$service}";
        if ($chain !== null) {
            $message .= "\nResolution chain:\n{$chain}";
        }
        return new self($message);
    }
}

