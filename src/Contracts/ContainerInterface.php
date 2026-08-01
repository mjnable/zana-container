<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Extends PSR-11 with additional resolution methods.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Resolve a service by its identifier.
     *
     * @template T
     * @param class-string<T> $id
     * @return T
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function get(string $id): mixed;

    /**
     * Check if a service is registered or can be auto-wired.
     */
    public function has(string $id): bool;

    /**
     * Resolve all implementations of a service.
     *
     * @template T
     * @param class-string<T> $id
     * @return array<int, T>
     */
    public function getAll(string $id): array;

    /**
     * Try to resolve a service; returns null if not found.
     *
     * @template T
     * @param class-string<T> $id
     * @return T|null
     */
    public function tryGet(string $id): mixed;

    /**
     * Create a new execution scope.
     */
    public function createScope(?ScopeMetadata $metadata = null): ServiceScopeInterface;
}

