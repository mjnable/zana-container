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
    public function singleton(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

    public function scoped(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

    public function transient(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function trySingleton(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function tryScoped(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

    /** Register only if no registration of the service (with that key) exists yet. */
    public function tryTransient(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static;

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

    /**
     * Call $register($this) now if the condition holds.
     *
     * @param bool|Closure(): bool $condition
     * @param Closure(static): mixed $register
     */
    public function when(bool|Closure $condition, Closure $register): static;

    /**
     * Call $register($this) now if the profile is active.
     *
     * @param Closure(static): mixed $register
     */
    public function profile(string $profile, Closure $register): static;

    /**
     * @return list<string> Profiles given to the constructor
     */
    public function activeProfiles(): array;

    /**
     * Register classes from their #[Singleton], #[Scoped] or #[Transient] attribute.
     *
     * @param class-string ...$classes
     * @throws \Zana\Container\Exception\ContainerException If a class has no lifetime attribute or several
     */
    public function addAttributed(string ...$classes): static;

    /**
     * Register every attributed class under a PSR-4 directory. Runs at build time;
     * scanned registrations come before explicit ones, so explicit ones win.
     */
    public function scan(string $directory, string $namespace, ?string $origin = null): static;

    public function addParameterProvider(ParameterValueProviderInterface $provider): static;

    /** Wrap the creation of every new instance; the first added is outermost. */
    public function addInterceptor(ResolutionInterceptorInterface $interceptor): static;

    public function addScopeObserver(ScopeObserverInterface $observer): static;

    /** Declare that a capability is present. */
    public function provide(string $capability, ?string $origin = null): static;

    /** Declare that a capability is needed; the build fails (B14) if nobody provides it. */
    public function requires(string $capability, ?string $origin = null): static;

    /**
     * Register more services at build time if the capability is provided.
     *
     * @param Closure(static): mixed $register
     */
    public function whenCapability(string $capability, Closure $register): static;

    public function has(string $service, ?string $key = null): bool;

    /**
     * @return list<ServiceDescriptor> In registration order
     */
    public function descriptors(): array;

    /**
     * Run pending scans and capability callbacks, then validate without creating
     * any service. The report carries the errors and the graph, even when invalid.
     */
    public function analyze(): \Zana\Container\BuildReport;

    /**
     * Validate every registration and produce the container.
     *
     * With $compiled, use a class generated by ContainerCompiler instead of
     * validating; it must match the current registrations.
     *
     * @param class-string|null $compiled
     * @throws \Zana\Container\Exception\ContainerBuildException If any validation rule fails
     * @throws \Zana\Container\Exception\StaleCompilationException If $compiled no longer matches
     */
    public function build(?string $compiled = null): ContainerInterface;
}
