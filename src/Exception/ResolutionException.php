<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when creating a service fails: a constructor, factory or decorator
 * throws, or a factory returns the wrong type.
 */
class ResolutionException extends ContainerException
{
    /**
     * @param list<string> $chain
     */
    public static function creationFailed(string $service, array $chain, \Throwable $previous): self
    {
        return new self(
            sprintf(
                'Unable to resolve %s: %s threw "%s".',
                $service,
                get_debug_type($previous),
                $previous->getMessage()
            ) . self::formatChain($chain),
            0,
            $previous
        );
    }

    /**
     * @param list<string> $chain
     */
    public static function invalidResult(string $producer, string $service, mixed $result, array $chain): self
    {
        return new self(
            sprintf('%s must return an instance of %s; got %s.', $producer, $service, get_debug_type($result))
            . self::formatChain($chain)
        );
    }
}
