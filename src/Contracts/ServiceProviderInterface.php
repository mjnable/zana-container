<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ServiceProviderInterface extends PsrContainerInterface
{
    public function get(string $id): mixed;

    public function has(string $id): bool;

    public function getAll(string $service): array;

    public function createScope(?ScopeMetadata $metadata = null): ServiceScopeInterface;
}

