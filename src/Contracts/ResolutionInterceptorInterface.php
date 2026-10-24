<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Zana\Container\ResolutionContext;

/**
 * Wraps the creation of every new instance (not cache hits, not instance()
 * registrations), for cross-cutting concerns such as profiling or tracing.
 *
 * The first registered interceptor is the outermost. Decorators are applied
 * to the intercepted result.
 */
interface ResolutionInterceptorInterface
{
    /**
     * @param \Closure(): object $next Creates the instance (or calls the next interceptor)
     * @return object An instance of the service, usually the one $next returned
     */
    public function intercept(ResolutionContext $context, \Closure $next): object;
}
