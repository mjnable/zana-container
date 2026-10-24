<?php

declare(strict_types=1);

namespace Zana\Container;

use Closure;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Internal\BuildValidator;
use Zana\Container\Internal\Engine;
use Zana\Container\Internal\Registry;

/**
 * Records service registrations and builds a validated container from them.
 *
 * Registering the same service again appends: get() returns the last
 * registration and getAll() returns all of them.
 */
class ServiceCollection implements ServiceCollectionInterface
{
    /** @var list<ServiceDescriptor> */
    private array $descriptors = [];

    /** @var array<string, string> alias => target */
    private array $aliases = [];

    /** @var array<string, list<string>> tag => services */
    private array $tags = [];

    /** @var array<string, list<class-string|Closure>> service => decorators */
    private array $decorators = [];

    private bool $built = false;

    public function singleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Singleton, $key, $origin);
    }

    public function scoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Scoped, $key, $origin);
    }

    public function transient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Transient, $key, $origin);
    }

    public function trySingleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->singleton($service, $implementation, $key, $origin);
    }

    public function tryScoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->scoped($service, $implementation, $key, $origin);
    }

    public function tryTransient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->transient($service, $implementation, $key, $origin);
    }

    public function instance(string $service, object $instance, ?string $key = null, ?string $origin = null): static
    {
        $this->assertNotBuilt();

        $this->descriptors[] = new ServiceDescriptor(
            service: $service,
            lifetime: ServiceLifetime::Singleton,
            instance: $instance,
            key: $key,
            origin: $origin,
        );

        return $this;
    }

    public function replace(string $service, string|Closure $implementation, ?string $key = null, ?ServiceLifetime $lifetime = null, ?string $origin = null): static
    {
        $this->assertNotBuilt();

        $replaced = array_values(array_filter(
            $this->descriptors,
            static fn (ServiceDescriptor $d): bool => $d->service === $service && $d->key === $key
        ));

        if ($replaced === []) {
            throw ContainerException::nothingToReplace($service, $key);
        }

        $this->removeRegistrations($service, $key);

        return $this->add($service, $implementation, $lifetime ?? $replaced[array_key_last($replaced)]->lifetime, $key, $origin);
    }

    public function remove(string $service, ?string $key = null): static
    {
        $this->assertNotBuilt();

        $this->removeRegistrations($service, $key);

        if ($key === null) {
            unset($this->aliases[$service], $this->decorators[$service]);

            foreach ($this->tags as $tag => $services) {
                $this->tags[$tag] = array_values(array_diff($services, [$service]));
            }
        }

        return $this;
    }

    public function alias(string $alias, string $target): static
    {
        $this->assertNotBuilt();

        if ($alias === $target) {
            throw ContainerException::selfAlias($alias);
        }

        $this->aliases[$alias] = $target;

        return $this;
    }

    public function tag(string $tag, string ...$services): static
    {
        $this->assertNotBuilt();

        foreach ($services as $service) {
            if (!in_array($service, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $service;
            }
        }

        return $this;
    }

    public function decorate(string $service, string|Closure $decorator): static
    {
        $this->assertNotBuilt();

        /** @var class-string|Closure $decorator */
        $this->decorators[$service][] = $decorator;

        return $this;
    }

    public function has(string $service, ?string $key = null): bool
    {
        return $this->isRegistered($service, $key) || ($key === null && isset($this->aliases[$service]));
    }

    public function descriptors(): array
    {
        return $this->descriptors;
    }

    public function build(): ContainerInterface
    {
        $this->assertNotBuilt();

        $registry = new Registry($this->descriptors, $this->aliases, $this->tags, $this->decorators);
        $result = (new BuildValidator($registry))->validate();

        if ($result->errors !== []) {
            throw ContainerBuildException::withErrors($result->errors);
        }

        $this->built = true;

        return new Container($registry, new Engine($registry, $result->plans, $result->decoratorPlans));
    }

    private function add(string $service, string|Closure|null $implementation, ServiceLifetime $lifetime, ?string $key, ?string $origin): static
    {
        $this->assertNotBuilt();

        $this->descriptors[] = new ServiceDescriptor(
            service: $service,
            lifetime: $lifetime,
            implementation: $implementation instanceof Closure ? null : ($implementation ?? $service),
            factory: $implementation instanceof Closure ? $implementation : null,
            key: $key,
            origin: $origin,
        );

        return $this;
    }

    private function isRegistered(string $service, ?string $key): bool
    {
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->service === $service && $descriptor->key === $key) {
                return true;
            }
        }

        return false;
    }

    private function removeRegistrations(string $service, ?string $key): void
    {
        $this->descriptors = array_values(array_filter(
            $this->descriptors,
            static fn (ServiceDescriptor $d): bool => !($d->service === $service && $d->key === $key)
        ));
    }

    /**
     * A try* call that registers nothing must still respect the frozen state.
     */
    private function unlessBuilt(): static
    {
        $this->assertNotBuilt();

        return $this;
    }

    private function assertNotBuilt(): void
    {
        if ($this->built) {
            throw ContainerException::alreadyBuilt();
        }
    }
}
