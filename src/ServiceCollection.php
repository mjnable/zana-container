<?php

declare(strict_types=1);

namespace Zana\Container;

use Zana\Contracts\Container\ServiceCollectionInterface;
use Zana\Contracts\Container\ContainerInterface;
use Zana\Contracts\Container\ServiceLifetime;
use Zana\Container\Descriptors\ServiceDescriptor;

class ServiceCollection implements ServiceCollectionInterface
{
    /** @var array<string, ServiceDescriptor[]> */
    private array $descriptors = [];

    /** @var array<string, string> */
    private array $aliases = [];

    private bool $built = false;

    public function singleton(string $service, mixed $implementation = null): static
    {
        return $this->addDescriptor(
            $service,
            $implementation ?? $service,
            ServiceLifetime::Singleton
        );
    }

    public function scoped(string $service, mixed $implementation = null): static
    {
        return $this->addDescriptor(
            $service,
            $implementation ?? $service,
            ServiceLifetime::Scoped
        );
    }

    public function transient(string $service, mixed $implementation = null): static
    {
        return $this->addDescriptor(
            $service,
            $implementation ?? $service,
            ServiceLifetime::Transient
        );
    }

    public function instance(string $service, object $instance): static
    {
        $this->assertNotBuilt();

        $this->descriptors[$service] = [
            new ServiceDescriptor(
                service: $service,
                lifetime: ServiceLifetime::Singleton,
                instance: $instance
            )
        ];

        return $this;
    }

    public function alias(string $alias, string $service): static
    {
        $this->assertNotBuilt();
        $this->aliases[$alias] = $service;
        return $this;
    }

    public function remove(string $service): static
    {
        $this->assertNotBuilt();
        unset($this->descriptors[$service], $this->aliases[$service]);
        return $this;
    }

    public function has(string $service): bool
    {
        return isset($this->descriptors[$service]) || isset($this->aliases[$service]);
    }

    public function build(): ContainerInterface
    {
        $this->assertNotBuilt();
        $this->built = true;
        return new ServiceProvider($this->descriptors, $this->aliases);
    }

    private function addDescriptor(string $service, mixed $implementation, ServiceLifetime $lifetime): static
    {
        $this->assertNotBuilt();

        $descriptor = new ServiceDescriptor(
            service: $service,
            implementation: is_string($implementation) ? $implementation : null,
            lifetime: $lifetime,
            factory: $implementation instanceof \Closure ? $implementation : null
        );

        if (!isset($this->descriptors[$service])) {
            $this->descriptors[$service] = [];
        }

        $this->descriptors[$service][] = $descriptor;

        return $this;
    }

    private function assertNotBuilt(): void
    {
        if ($this->built) {
            throw new Exception\ContainerException('Service collection has already been built.');
        }
    }

    /**
     * @return array<string, ServiceDescriptor[]>
     */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    /**
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
