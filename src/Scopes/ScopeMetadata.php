<?php

declare(strict_types=1);

namespace Zana\Container\Scopes;

use DateTimeImmutable;
use Zana\Contracts\Container\ScopeMetadataInterface;
use Zana\Contracts\Container\ScopeType;

/**
 * Concrete scope metadata implementation.
 *
 * Lives in the container package (implementation detail) and implements the
 * complete behavioral {@see ScopeMetadataInterface} contract from zana/contracts.
 *
 * Internal state is kept private and exposed via getter methods so the
 * representation can change without breaking the contract.
 */
class ScopeMetadata implements ScopeMetadataInterface
{
    private string $id;
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly ScopeType $type = ScopeType::Root,
        ?string $id = null,
        private readonly ?string $correlationId = null,
        private readonly ?string $tenantId = null,
        private readonly ?string $userId = null,
        private readonly array $attributes = [],
        private readonly ?ScopeMetadataInterface $parent = null,
    ) {
        $this->id = $id ?? uniqid('scope-', true);
        $this->createdAt = new DateTimeImmutable();
    }

    public function type(): ScopeType
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->type->name;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function parent(): ?ScopeMetadataInterface
    {
        return $this->parent;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public static function http(?string $requestId = null): self
    {
        return new self(
            type: ScopeType::Http,
            id: $requestId ?? uniqid('http-', true),
            correlationId: $requestId
        );
    }

    public static function cli(?string $command = null): self
    {
        return new self(
            type: ScopeType::Cli,
            id: $command ?? uniqid('cli-', true)
        );
    }

    public static function test(): self
    {
        return new self(
            type: ScopeType::Test,
            id: uniqid('test-', true)
        );
    }

    public static function root(): self
    {
        return new self(
            type: ScopeType::Root,
            id: 'root'
        );
    }
}
