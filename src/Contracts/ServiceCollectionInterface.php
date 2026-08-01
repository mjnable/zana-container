<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

interface ServiceCollectionInterface
{
    public function singleton(string $service, mixed $implementation = null): static;

    public function scoped(string $service, mixed $implementation = null): static;

    public function transient(string $service, mixed $implementation = null): static;

    public function instance(string $service, object $instance): static;

    public function alias(string $alias, string $service): static;

    public function remove(string $service): static;

    public function has(string $service): bool;

    public function build(): ContainerInterface;
}

