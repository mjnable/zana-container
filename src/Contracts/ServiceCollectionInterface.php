<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Closure;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

/**
 * Registration API. Frozen once build() succeeds.
 *
 * An implementation is a class name or a factory closure that receives the
 * owning resolver: fn(ServiceResolverInterface $resolver): object.
 */
interface ServiceCollectionInterface
{
    public function singleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    public function scoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    public function transient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function trySingleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function tryScoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function tryTransient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static;

    /** Register an existing object as a singleton. The container never disposes it. */
    public function instance(string $service, object $instance, ?string $key = null, ?string $origin = null): static;

    /**
     * Replace every registration of the service (with that key) by one registration.
     * Tags, decorators and aliases are kept. The lifetime defaults to the last replaced one.
     *
     * @throws \Zana\Container\Exception\ContainerException If nothing is registered to replace
     */
    public function replace(string $service, string|Closure $implementation, ?string $key = null, ?ServiceLifetime $lifetime = null, ?string $origin = null): static;

    /**
     * Remove every registration of the service (with that key). Without a key this
     * also removes the id as an alias, from all tags, and its decorators.
     */
    public function remove(string $service, ?string $key = null): static;

    /** Make $alias resolve exactly as the unkeyed $target does. */
    public function alias(string $alias, string $target): static;

    /** Add unkeyed services to a named group resolved with tagged(). */
    public function tag(string $tag, string ...$services): static;

    /**
     * Wrap every resolution of a service.
     *
     * A class decorator receives the decorated instance through the one constructor
     * parameter typed with the service. A closure decorator is called as
     * fn(object $inner, ServiceResolverInterface $resolver): object, and may type
     * $inner with the service type.
     *
     * @param class-string|Closure $decorator
     */
    public function decorate(string $service, string|Closure $decorator): static;

    public function has(string $service, ?string $key = null): bool;

    /**
     * @return list<ServiceDescriptor> In registration order
     */
    public function descriptors(): array;

    /**
     * Validate every registration and produce the container.
     *
     * @throws \Zana\Container\Exception\ContainerBuildException If any validation rule fails
     */
    public function build(): ContainerInterface;
}
