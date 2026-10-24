<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when resolution re-enters a service that is already being resolved.
 */
class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $cycle Services in the cycle, ending with the re-entered one
     */
    public static function forCycle(array $cycle): self
    {
        return new self('Circular dependency detected: ' . implode(' → ', $cycle) . '.');
    }
}
