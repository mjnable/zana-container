<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

/**
 * Thrown when several services fail while being disposed.
 *
 * Disposal always continues past a failure; this exception collects every
 * failure. getPrevious() returns the first one.
 */
class DisposalException extends ContainerException
{
    /** @var list<\Throwable> */
    private array $errors = [];

    /**
     * @param non-empty-list<\Throwable> $errors
     */
    public static function fromErrors(array $errors): self
    {
        $lines = array_map(
            static fn (\Throwable $e): string => '  - ' . get_debug_type($e) . ': ' . $e->getMessage(),
            $errors
        );

        $exception = new self(
            sprintf("Disposal failed with %d errors:\n%s", count($errors), implode("\n", $lines)),
            0,
            $errors[0]
        );
        $exception->errors = $errors;

        return $exception;
    }

    /**
     * @return list<\Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
