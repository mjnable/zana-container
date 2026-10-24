<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * What is being resolved, passed to parameter value providers.
 */
final readonly class ResolutionContext
{
    /**
     * @param ServiceDescriptor|null $descriptor The registration being created; null inside call()
     * @param list<string> $chain Registrations being resolved, outermost first
     */
    public function __construct(
        public ?ServiceDescriptor $descriptor,
        public array $chain,
    ) {
    }
}
