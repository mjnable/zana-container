<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when a circular dependency is detected during resolution.
 */
class CircularDependencyException extends ContainerException
{
    public static function detected(string $service, array $chain): self
    {
        $chainStr = implode(' → ', array_reverse($chain));
        return new self(
            "Circular dependency detected for: {$service}\n" .
            "Dependency chain: {$chainStr} → {$service}"
        );
    }
}

