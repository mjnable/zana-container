<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

use Zana\Container\BuildError;

/**
 * Thrown by ServiceCollection::build() when validation fails. Lists every error.
 */
class ContainerBuildException extends ContainerException
{
    /** @var list<BuildError> */
    private array $errors = [];

    /**
     * @param non-empty-list<BuildError> $errors
     */
    public static function withErrors(array $errors): self
    {
        $count = count($errors);
        $lines = array_map(static fn (BuildError $e): string => '  ' . $e, $errors);

        $exception = new self(sprintf(
            "Container build failed with %d error%s:\n%s",
            $count,
            $count === 1 ? '' : 's',
            implode("\n", $lines)
        ));
        $exception->errors = $errors;

        return $exception;
    }

    /**
     * @return list<BuildError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
