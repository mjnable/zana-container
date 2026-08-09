<?php

declare(strict_types=1);

namespace Zana\Container\Descriptors;

use Zana\Contracts\Container\ServiceLifetime;

/**
 * Immutable metadata representing a service registration.
 *
 * Acts as the internal contract between ServiceCollection (registration)
 * and ServiceProvider (resolution). This is an implementation-level value
 * object, so it stays in the container package rather than in zana/contracts.
 *
 * Only one creation strategy is active at a time:
 * - implementation (class name)
 * - factory (closure)
 * - instance (existing object)
 */
final readonly class ServiceDescriptor
{
    /**
     * @param string $service The service identifier (interface or class name)
     * @param string|null $implementation The concrete class name (null for factory/instance)
     * @param ServiceLifetime $lifetime How long the service instance lives
     * @param \Closure|null $factory Custom factory for creating the instance
     * @param object|null $instance An existing object instance
     * @param array $metadata Additional metadata (tags, capabilities, package, module)
     */
    public function __construct(
        public string $service,
        public ?string $implementation = null,
        public ServiceLifetime $lifetime = ServiceLifetime::Transient,
        public ?\Closure $factory = null,
        public ?object $instance = null,
        public array $metadata = [],
    ) {
        $strategies = 0;
        if ($implementation !== null) {
            $strategies++;
        }
        if ($factory !== null) {
            $strategies++;
        }
        if ($instance !== null) {
            $strategies++;
        }

        if ($strategies > 1) {
            throw new \InvalidArgumentException(
                'Only one creation strategy is active at a time: '
                . 'implementation, factory, or instance. '
                . 'Received ' . $strategies . ' strategies.'
            );
        }
    }

    /**
     * Check if this descriptor represents a self-registration
     * (service and implementation are the same class).
     */
    public function isSelfRegistered(): bool
    {
        return $this->implementation === null
            && $this->factory === null
            && $this->instance === null;
    }

    /**
     * Check if this descriptor uses a factory.
     */
    public function isFactory(): bool
    {
        return $this->factory !== null;
    }

    /**
     * Check if this descriptor uses an existing instance.
     */
    public function isInstance(): bool
    {
        return $this->instance !== null;
    }

    /**
     * Check if this descriptor has a concrete implementation class.
     */
    public function hasImplementation(): bool
    {
        return $this->implementation !== null;
    }

    /**
     * Get the effective concrete type to instantiate.
     * For self-registrations, this is the service itself.
     */
    public function getConcreteType(): string
    {
        if ($this->implementation !== null) {
            return $this->implementation;
        }
        return $this->service;
    }
}
