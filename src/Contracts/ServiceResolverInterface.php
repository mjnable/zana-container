<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Anything services can be resolved from: the root container or a scope.
 */
interface ServiceResolverInterface extends PsrContainerInterface
{
    /**
     * Resolve the last unkeyed registration of a service (aliases are followed).
     *
     * @throws \Zana\Container\Exception\NotFoundException If the service is not registered
     * @throws \Zana\Container\Exception\ContainerException If creating it or a dependency fails
     */
    public function get(string $id): mixed;

    /**
     * Whether an unkeyed registration or alias exists for the id.
     */
    public function has(string $id): bool;

    /**
     * Resolve the last registration of a service with the given key.
     *
     * @throws \Zana\Container\Exception\NotFoundException If no such keyed registration exists
     * @throws \Zana\Container\Exception\ContainerException If creating it or a dependency fails
     */
    public function getKeyed(string $service, string $key): mixed;

    public function hasKeyed(string $service, string $key): bool;

    /**
     * Resolve every unkeyed registration of a service, in registration order.
     *
     * @return list<object>
     */
    public function getAll(string $service): array;

    /**
     * Resolve every member of a tag, in tagging order.
     *
     * @return list<object>
     */
    public function tagged(string $tag): array;

    /**
     * Invoke a callable with its parameters resolved from this resolver.
     *
     * Accepts a closure, an invokable object, "Class::method" (static),
     * [object, method], or [class, method] where the class is a registered service.
     * Named $arguments take precedence over resolution; positional ones are rejected.
     * Exceptions thrown by the callable itself propagate unchanged.
     *
     * @param callable|array{object|string, string}|string $callable
     * @param array<string, mixed> $arguments
     * @throws \Zana\Container\Exception\ContainerException If a parameter cannot be resolved
     */
    public function call(callable|array|string $callable, array $arguments = []): mixed;

    /**
     * Dispose the services this resolver owns. Idempotent.
     */
    public function dispose(): void;

    public function isDisposed(): bool;
}
