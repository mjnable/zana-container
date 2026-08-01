<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

enum ScopeType
{
    case Root;
    case Http;
    case Cli;
    case Queue;
    case Scheduler;
    case Test;
    case Custom;
}

class ScopeMetadata
{
    public function __construct(
        public readonly ScopeType $type = ScopeType::Root,
        public readonly ?string $id = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $userId = null,
        public readonly array $attributes = []
    ) {
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

