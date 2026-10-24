<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * Immutable description of the root container or a scope.
 *
 * The container fills in the id (when not given), the parent id and the
 * creation time when the scope is created. Attribute keys are defined by the
 * packages that use them (e.g. zana/http sets "correlation_id"); the container
 * gives them no meaning.
 */
final readonly class ScopeMetadata
{
    public string $id;

    /**
     * @param string $type "root", "http", "cli", "queue", "test", or any other kind
     * @param array<string, scalar|null> $attributes
     */
    public function __construct(
        public string $type,
        public ?string $name = null,
        public array $attributes = [],
        ?string $id = null,
        public ?string $parentId = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(8));
    }

    public static function root(): self
    {
        return new self('root', 'root');
    }

    public static function http(?string $requestId = null): self
    {
        return new self('http', id: $requestId);
    }

    public static function cli(?string $command = null): self
    {
        return new self('cli', $command);
    }

    public static function queue(?string $job = null): self
    {
        return new self('queue', $job);
    }

    public static function test(?string $name = null): self
    {
        return new self('test', $name);
    }

    public function withAttribute(string $key, string|int|float|bool|null $value): self
    {
        return new self($this->type, $this->name, [...$this->attributes, $key => $value], $this->id, $this->parentId, $this->createdAt);
    }

    public function attribute(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @internal Called by the container when the scope is created.
     */
    public function completed(?string $parentId): self
    {
        return new self($this->type, $this->name, $this->attributes, $this->id, $parentId, $this->createdAt ?? new \DateTimeImmutable());
    }
}
