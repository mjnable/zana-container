<?php

declare(strict_types=1);

namespace Zana\Container;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Zana\Contracts\Container\ContainerInterface;
use Zana\Contracts\Container\ServiceProviderInterface;
use Zana\Contracts\Container\ServiceScopeInterface;
use Zana\Contracts\Container\ScopeMetadataInterface;
use Zana\Contracts\Container\ServiceLifetime;
use Zana\Container\Descriptors\ServiceDescriptor;
use Zana\Container\Scopes\ServiceScope;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Exception\CircularDependencyException;

class ServiceProvider implements ContainerInterface, ServiceProviderInterface
{
    /** @var array<string, ServiceDescriptor[]> */
    private array $descriptors;

    /** @var array<string, string> */
    private array $aliases;

    /** @var array<string, object> */
    private array $singletonCache = [];

    /** @var array<string, object> */
    private array $scopedCache = [];

    /** @var array<string, bool> */
    private array $resolutionStack = [];

    private ?ServiceScope $scope = null;

    private ?ServiceProvider $parent = null;

    /**
     * @param array<string, ServiceDescriptor[]> $descriptors
     * @param array<string, string> $aliases
     */
    public function __construct(array &$descriptors, array &$aliases)
    {
        $this->descriptors = &$descriptors;
        $this->aliases = &$aliases;
    }

    public function setParent(ServiceProvider $parent): void
    {
        $this->parent = $parent;
    }

    public function get(string $id): mixed
    {
        $id = $this->resolveAlias($id);

        // Check singleton cache first
        if (isset($this->singletonCache[$id])) {
            return $this->singletonCache[$id];
        }

        // Check scoped cache (current scope first, then provider fallback)
        if ($this->scope !== null && $this->scope->hasCached($id)) {
            return $this->scope->getCached($id);
        }
        if (isset($this->scopedCache[$id])) {
            return $this->scopedCache[$id];
        }

        // Check parent's singleton cache
        if ($this->parent && isset($this->parent->singletonCache[$id])) {
            return $this->parent->singletonCache[$id];
        }

        $descriptors = $this->findDescriptors($id);

        if (empty($descriptors)) {
            // Try auto-wiring for concrete class — route through resolveDescriptor
            // so circular dependency detection is handled in one place.
            if (class_exists($id)) {
                $syntheticDescriptor = new ServiceDescriptor(
                    $id, $id, ServiceLifetime::Transient
                );
                return $this->resolveDescriptor($syntheticDescriptor);
            }
            throw new NotFoundException("Service '{$id}' not found.");
        }

        // Use the last registered descriptor (allows override)
        $descriptor = end($descriptors);

        return $this->resolveDescriptor($descriptor);
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        if (isset($this->descriptors[$id])) {
            return true;
        }

        if (isset($this->singletonCache[$id])) {
            return true;
        }

        if ($this->parent && $this->parent->has($id)) {
            return true;
        }

        // Check auto-wirable concrete class
        return class_exists($id);
    }

    public function getAll(string $service): array
    {
        $service = $this->resolveAlias($service);
        $descriptors = $this->findDescriptors($service);
        $results = [];

        foreach ($descriptors as $descriptor) {
            $results[] = $this->resolveDescriptor($descriptor);
        }

        return $results;
    }

    public function createScope(?ScopeMetadataInterface $metadata = null): ServiceScopeInterface
    {
        $scope = new ServiceScope($this, $metadata);
        return $scope;
    }

    public function setScope(ServiceScope $scope): void
    {
        $this->scope = $scope;
    }

    public function getScope(): ?ServiceScope
    {
        return $this->scope;
    }

    public function getParentProvider(): ?ServiceProvider
    {
        return $this->parent;
    }

    private function resolveAlias(string $id): string
    {
        if (isset($this->aliases[$id])) {
            return $this->resolveAlias($this->aliases[$id]);
        }

        if ($this->parent) {
            // Check parent aliases
            $parentId = $this->parent->resolveAliasRaw($id);
            if ($parentId !== $id) {
                return $parentId;
            }
        }

        return $id;
    }

    private function resolveAliasRaw(string $id): string
    {
        if (isset($this->aliases[$id])) {
            return $this->resolveAliasRaw($this->aliases[$id]);
        }
        return $id;
    }

    /**
     * @return ServiceDescriptor[]
     */
    private function findDescriptors(string $service): array
    {
        if (isset($this->descriptors[$service])) {
            return $this->descriptors[$service];
        }

        if ($this->parent && isset($this->parent->descriptors[$service])) {
            return $this->parent->descriptors[$service];
        }

        return [];
    }

    private function resolveDescriptor(ServiceDescriptor $descriptor): mixed
    {
        // Return existing instance
        if ($descriptor->instance !== null) {
            return $descriptor->instance;
        }

        $service = $descriptor->service;

        // Check for circular dependencies
        if (isset($this->resolutionStack[$service])) {
            throw new CircularDependencyException(
                "Circular dependency detected for '{$service}'. " .
                "Chain: " . implode(' → ', array_keys($this->resolutionStack)) . " → {$service}"
            );
        }

        $this->resolutionStack[$service] = true;

        try {
            $instance = $this->createInstance($descriptor);
        } finally {
            unset($this->resolutionStack[$service]);
        }

        // Cache based on lifetime
        return match ($descriptor->lifetime) {
            ServiceLifetime::Singleton => $this->singletonCache[$service] ??= $instance,
            ServiceLifetime::Scoped => $this->cacheScoped($service, $instance),
            ServiceLifetime::Transient => $instance,
        };
    }

    private function cacheScoped(string $service, object $instance): object
    {
        // When resolving inside a scope, cache the instance on the scope itself
        // so it is isolated per-scope and released when the scope is disposed.
        if ($this->scope !== null) {
            if (!$this->scope->hasCached($service)) {
                $this->scope->cache($service, $instance);
            }
            return $this->scope->getCached($service);
        }

        return $this->scopedCache[$service] ??= $instance;
    }

    public function tryGet(string $id): mixed
    {
        try {
            return $this->get($id);
        } catch (\Psr\Container\NotFoundExceptionInterface) {
            return null;
        }
    }

    public function register(ServiceProvider $provider): void
    {
        // No-op in the base provider; child providers can override
    }

    public function boot(): void
    {
        // No-op in the base provider; child providers can override
    }

    private function createInstance(ServiceDescriptor $descriptor): object
    {
        // Factory
        if ($descriptor->factory !== null) {
            $factory = $descriptor->factory;
            return $factory($this);
        }

        // Existing instance
        if ($descriptor->instance !== null) {
            return $descriptor->instance;
        }

        // Class implementation
        $class = $descriptor->getConcreteType();

        if (!class_exists($class)) {
            throw new ResolutionException("Class '{$class}' does not exist.");
        }

        return $this->autoWire($class);
    }

    private function autoWire(string $class): object
    {
        $ref = new \ReflectionClass($class);

        $constructor = $ref->getConstructor();

        // No constructor — instantiate directly
        if ($constructor === null) {
            return $ref->newInstance();
        }

        $params = $constructor->getParameters();
        $args = [];

        foreach ($params as $param) {
            $args[] = $this->resolveParameter($class, $param);
        }

        return $ref->newInstanceArgs($args);
    }

    private function resolveParameter(string $class, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        // No type hint
        if ($type === null) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw ResolutionException::unresolvedDependency(
                $class,
                $param->getName(),
                'mixed (no type hint)'
            );
        }

        // Named type (class, interface, built-in)
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();

            // Built-in types (string, int, array, etc.)
            if ($type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                throw ResolutionException::unresolvedDependency(
                    $class,
                    $param->getName(),
                    $typeName
                );
            }

            // Class/interface/enum type — resolve from container
            try {
                return $this->get($typeName);
            } catch (NotFoundException $e) {
                if ($param->allowsNull()) {
                    return null;
                }
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                throw ResolutionException::unresolvedDependency(
                    $class,
                    $param->getName(),
                    $typeName,
                    $e->getMessage()
                );
            }
        }

        // Union or intersection type
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($param->allowsNull()) {
            return null;
        }

        throw ResolutionException::unresolvedDependency(
            $class,
            $param->getName(),
            'complex type'
        );
    }
}
