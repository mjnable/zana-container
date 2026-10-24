<?php

declare(strict_types=1);

namespace Zana\Container\Graph;

/**
 * One registration (or alias) in the service graph.
 */
final readonly class ServiceNode
{
    /**
     * @param int $id Position in ServiceGraph::nodes(), referenced by edges
     * @param string|null $lifetime "singleton", "scoped" or "transient"; null for aliases
     * @param string $strategy "class", "factory", "instance" or "alias"
     * @param string|null $implementation The class for class registrations, the target for aliases
     * @param list<string> $tags
     * @param list<string> $decorators Decorator class names, or "closure"
     */
    public function __construct(
        public int $id,
        public string $service,
        public ?string $key,
        public ?string $lifetime,
        public string $strategy,
        public ?string $implementation,
        public ?string $origin,
        public array $tags,
        public array $decorators,
        public bool $lazy,
    ) {
    }

    /**
     * @return array{id: int, service: string, key: ?string, lifetime: ?string, strategy: string, implementation: ?string, origin: ?string, tags: list<string>, decorators: list<string>, lazy: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'key' => $this->key,
            'lifetime' => $this->lifetime,
            'strategy' => $this->strategy,
            'implementation' => $this->implementation,
            'origin' => $this->origin,
            'tags' => $this->tags,
            'decorators' => $this->decorators,
            'lazy' => $this->lazy,
        ];
    }
}
