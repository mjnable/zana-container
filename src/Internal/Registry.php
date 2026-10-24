<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Closure;
use Zana\Container\ServiceDescriptor;

/**
 * Frozen registrations of a built container, indexed for lookup.
 *
 * Descriptors are identified by their position in registration order; caches
 * and validation refer to that index.
 *
 * @internal
 */
final class Registry
{
    private const UNKEYED = "\0";

    /** @var array<string, array<string, list<int>>> service => key => descriptor indexes */
    private array $index = [];

    /**
     * @param list<ServiceDescriptor> $descriptors
     * @param array<string, string> $aliases alias => target
     * @param array<string, list<string>> $tags tag => services
     * @param array<string, list<class-string|Closure>> $decorators service => decorators, innermost first
     */
    public function __construct(
        public readonly array $descriptors,
        public readonly array $aliases,
        public readonly array $tags,
        public readonly array $decorators,
    ) {
        foreach ($descriptors as $position => $descriptor) {
            $this->index[$descriptor->service][$descriptor->key ?? self::UNKEYED][] = $position;
        }
    }

    public function descriptor(int $index): ServiceDescriptor
    {
        return $this->descriptors[$index];
    }

    /**
     * Follow an alias chain to the service it names. Returns the id itself when it is not an alias.
     */
    public function resolveAlias(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    /**
     * Index of the last registration of the service with the key, or null.
     */
    public function last(string $service, ?string $key = null): ?int
    {
        $indexes = $this->index[$service][$key ?? self::UNKEYED] ?? [];

        return $indexes === [] ? null : $indexes[array_key_last($indexes)];
    }

    /**
     * @return list<int> Indexes of every registration of the service with the key, in order
     */
    public function all(string $service, ?string $key = null): array
    {
        return $this->index[$service][$key ?? self::UNKEYED] ?? [];
    }

    public function isRegistered(string $service, ?string $key = null): bool
    {
        return isset($this->index[$service][$key ?? self::UNKEYED]);
    }

    /**
     * Whether the id has any registration, keyed or not.
     */
    public function isRegisteredWithAnyKey(string $service): bool
    {
        return isset($this->index[$service]);
    }

    /**
     * Whether get($id) can succeed: an unkeyed registration or an alias.
     */
    public function isResolvable(string $id): bool
    {
        return isset($this->aliases[$id]) || $this->isRegistered($id);
    }

    /**
     * @return list<class-string|Closure>
     */
    public function decoratorsOf(string $service): array
    {
        return $this->decorators[$service] ?? [];
    }

    /**
     * @return list<string>
     */
    public function tagged(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }
}
