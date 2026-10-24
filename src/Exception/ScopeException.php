<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when a scoped service is requested outside a scope, or a disposed
 * container or scope is used.
 */
class ScopeException extends ContainerException
{
    /**
     * @param list<string> $chain
     */
    public static function scopedFromRoot(string $service, array $chain): self
    {
        return new self(
            "Scoped service {$service} cannot be resolved from the root container. "
            . 'Resolve it from a scope created with createScope().'
            . self::formatChain($chain)
        );
    }

    public static function disposed(string $resolver): self
    {
        return new self("This {$resolver} has been disposed and can no longer resolve services.");
    }
}
