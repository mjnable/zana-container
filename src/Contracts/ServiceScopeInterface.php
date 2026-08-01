<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

interface ServiceScopeInterface
{
    public function getProvider(): ServiceProviderInterface;

    public function getMetadata(): ?ScopeMetadata;

    public function getParent(): ?ServiceScopeInterface;

    public function dispose(): void;
}

