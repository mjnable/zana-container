<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * How long a resolved service instance lives.
 */
enum ServiceLifetime
{
    /** One instance per root container. */
    case Singleton;

    /** One instance per scope. Cannot be resolved from the root container. */
    case Scoped;

    /** A new instance on every resolution. */
    case Transient;
}
