<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Base exception for all container errors.
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
    public static function alreadyBuilt(): self
    {
        return new self('The service collection is already built; it cannot be changed or built again.');
    }

    public static function nothingToReplace(string $service, ?string $key): self
    {
        return new self(sprintf(
            'Cannot replace %s: it has no registration%s. Register it first, or use a try* method.',
            $service,
            $key === null ? '' : " with key '{$key}'"
        ));
    }

    public static function selfAlias(string $alias): self
    {
        return new self("An alias cannot point to itself: {$alias}.");
    }

    /**
     * Render a resolution chain as indented lines, outermost first.
     *
     * @param list<string> $chain
     */
    protected static function formatChain(array $chain): string
    {
        if ($chain === []) {
            return '';
        }

        return "\nResolution chain:\n  " . implode("\n  → ", $chain);
    }
}
