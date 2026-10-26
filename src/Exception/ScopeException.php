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

    /**
     * A singleton resolving a scoped service while it is created: the scoped
     * instance would outlive its scope.
     *
     * @param list<string> $chain
     */
    public static function capturedBySingleton(string $singleton, string $scoped, bool $factory, array $chain): self
    {
        return new self(
            "Singleton {$singleton} resolves scoped service {$scoped}"
            . ($factory ? ' in its factory' : '')
            . ', which would outlive its scope. Make the second a singleton, or the first scoped.'
            . ($factory ? ' Declare what the factory resolves with ->uses() so the container checks it at startup.' : '')
            . self::formatChain($chain)
        );
    }

    public static function disposed(string $resolver): self
    {
        return new self("This {$resolver} has been disposed and can no longer resolve services.");
    }
}
