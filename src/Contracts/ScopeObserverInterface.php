<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Zana\Container\ScopeMetadata;

/**
 * Notified when scopes are created and disposed (not for the root container).
 */
interface ScopeObserverInterface
{
    /**
     * Called before createScope() returns. Throwing disposes the scope and the exception propagates.
     */
    public function scopeCreated(ScopeInterface $scope): void;

    /**
     * Called before the scope disposes any service.
     */
    public function scopeDisposing(ScopeInterface $scope): void;

    /**
     * Called after the scope disposed its services.
     */
    public function scopeDisposed(ScopeMetadata $metadata): void;
}
