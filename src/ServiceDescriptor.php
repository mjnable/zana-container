<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * Immutable record of one service registration.
 *
 * Exactly one creation strategy is set: an implementation class, a factory
 * closure, or an existing instance. A self-registration stores the service
 * itself as the implementation.
 */
final readonly class ServiceDescriptor
{
    /**
     * @param string $service The service id (usually an interface or class name)
     * @param ServiceLifetime $lifetime How long resolved instances live
     * @param string|null $implementation Concrete class to construct
     * @param \Closure|null $factory Closure receiving the owning resolver and returning the instance
     * @param object|null $instance Existing object (never disposed by the container)
     * @param string|null $key Key of a keyed registration, null for unkeyed
     * @param bool $lazy Resolve as a proxy created on first use (class strategy only)
     * @param string|null $origin Who registered it, e.g. "module:Patients" (diagnostics only)
     * @param list<string> $uses Services the factory resolves, declared so the build can check them (factory strategy only)
     */
    public function __construct(
        public string $service,
        public ServiceLifetime $lifetime,
        public ?string $implementation = null,
        public ?\Closure $factory = null,
        public ?object $instance = null,
        public ?string $key = null,
        public bool $lazy = false,
        public ?string $origin = null,
        public array $uses = [],
    ) {
        if ($uses !== [] && $factory === null) {
            throw new \InvalidArgumentException('Only factory registrations declare what they use: the constructor of a class registration is checked already.');
        }

        if ($lazy && $implementation === null) {
            throw new \InvalidArgumentException('Only class registrations can be lazy, not factories or instances.');
        }

        if ($service === '') {
            throw new \InvalidArgumentException('The service id must not be empty.');
        }

        if ($key === '') {
            throw new \InvalidArgumentException(
                'A service key must not be empty. Use null for an unkeyed registration.'
            );
        }

        if ($implementation === '') {
            throw new \InvalidArgumentException('The implementation class name must not be empty.');
        }

        $strategies = ($implementation !== null ? 1 : 0)
            + ($factory !== null ? 1 : 0)
            + ($instance !== null ? 1 : 0);

        if ($strategies !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A service descriptor needs exactly one of implementation, factory or instance; %d given.',
                $strategies
            ));
        }
    }
}
