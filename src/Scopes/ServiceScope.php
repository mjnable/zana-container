<?php

declare(strict_types=1);

namespace Zana\Container\Scopes;

use Zana\Container\ServiceProvider;
use Zana\Contracts\Container\ServiceScopeInterface;
use Zana\Contracts\Container\ScopeMetadataInterface;
use Zana\Contracts\Container\DisposableInterface;

/**
 * Represents an execution scope for scoped service resolution.
 * 
 * Each scope isolates scoped service instances within a specific
 * execution context (HTTP request, CLI command, queue job, etc.).
 */
class ServiceScope implements ServiceScopeInterface
{
    private ?ServiceScope $parent = null;

    /** @var array<string, ServiceScope> */
    private array $children = [];

    /** @var array<string, object> */
    private array $scopedCache = [];

    private bool $disposed = false;

    /**
     * @param ServiceProvider $provider The service provider that created this scope
     * @param ScopeMetadataInterface|null $metadata Optional metadata for this scope
     */
    public function __construct(
        private readonly ServiceProvider $provider,
        private readonly ?ScopeMetadataInterface $metadata = null,
    ) {
    }

    /**
     * Get the service provider that owns this scope.
     */
    public function getProvider(): ServiceProvider
    {
        return $this->provider;
    }

    /**
     * Get the scope metadata.
     */
    public function getMetadata(): ?ScopeMetadataInterface
    {
        return $this->metadata;
    }

    /**
     * Get the parent scope, if any.
     */
    public function getParent(): ?ServiceScopeInterface
    {
        return $this->parent;
    }

    /**
     * Set the parent scope and register this scope as a child.
     */
    public function setParent(?ServiceScope $parent): void
    {
        $this->parent = $parent;

        if ($parent !== null) {
            $id = $this->metadata?->id() ?? spl_object_id($this);
            $parent->children[(string) $id] = $this;
        }
    }

    /**
     * Create a child scope nested within this scope.
     */
    public function createChild(?ScopeMetadataInterface $metadata = null): self
    {
        $child = new self($this->provider, $metadata);
        $child->setParent($this);
        return $child;
    }

    /**
     * Resolve a service within this scope.
     * Scoped services are cached per scope.
     */
    public function get(string $id): mixed
    {
        if ($this->disposed) {
            throw new \RuntimeException('Cannot resolve services from a disposed scope.');
        }

        // Set this scope on the provider for scoped cache resolution
        $this->provider->setScope($this);

        try {
            return $this->provider->get($id);
        } finally {
            // Restore the parent's scope on the provider
            if ($this->parent !== null) {
                $this->parent->provider->setScope($this->parent);
            }
        }
    }

    /**
     * Check if a service can be resolved within this scope.
     */
    public function has(string $id): bool
    {
        if ($this->disposed) {
            return false;
        }

        return $this->provider->has($id);
    }

    /**
     * Dispose the scope and all scoped service instances.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        // Dispose children first
        foreach ($this->children as $child) {
            $child->dispose();
        }
        $this->children = [];

        // Dispose scoped services that implement DisposableInterface
        foreach ($this->scopedCache as $service => $instance) {
            if ($instance instanceof DisposableInterface) {
                $instance->dispose();
            }
        }
        $this->scopedCache = [];

        // Remove from parent
        if ($this->parent !== null) {
            $id = $this->metadata?->id() ?? spl_object_id($this);
            unset($this->parent->children[(string) $id]);
        }

        $this->disposed = true;
    }

    /**
     * Check if this scope has been disposed.
     */
    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /**
     * Get scoped cache value.
     */
    public function getCached(string $id): mixed
    {
        return $this->scopedCache[$id] ?? null;
    }

    /**
     * Cache a scoped service instance.
     */
    public function cache(string $id, object $instance): void
    {
        $this->scopedCache[$id] = $instance;
    }

    /**
     * Check if a service is cached in this scope.
     */
    public function hasCached(string $id): bool
    {
        return isset($this->scopedCache[$id]);
    }

    public function __destruct()
    {
        if (!$this->disposed) {
            $this->dispose();
        }
    }
}
