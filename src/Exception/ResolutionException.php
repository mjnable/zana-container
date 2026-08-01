<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when a service cannot be resolved due to configuration or dependency issues.
 */
class ResolutionException extends ContainerException
{
    public static function unresolvedDependency(
        string $service,
        string $parameter,
        string $type,
        ?string $chain = null
    ): self {
        $message = sprintf(
            "Unable to resolve constructor parameter '%s' of type '%s' for service '%s'.",
            $parameter,
            $type,
            $service
        );
        if ($chain !== null) {
            $message .= "\nDependency chain:\n{$chain}";
        }
        $message .= "\n\nPossible solutions:";
        $message .= "\n- Register a binding for '{$type}'";
        $message .= "\n- Use a factory to create '{$service}' manually";
        $message .= "\n- Make the parameter optional (nullable or default value)";

        return new self($message);
    }
}

