<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * Defines the lifetime of a service in the container.
 */
enum ServiceLifetime
{
    /**
     * One instance for the entire application lifetime.
     * Created once, shared across all scopes and requests.
     */
    case Singleton;

    /**
     * One instance per execution scope (e.g., per HTTP request, per CLI command).
     * Shared within the same scope.
     */
    case Scoped;

    /**
     * A new instance is created every time the service is resolved.
     * No caching.
     */
    case Transient;
}

