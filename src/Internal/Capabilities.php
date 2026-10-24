<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\Contracts\CapabilitiesInterface;

/**
 * The capability set of a built container.
 *
 * @internal Type-hint CapabilitiesInterface instead.
 */
final readonly class Capabilities implements CapabilitiesInterface
{
    /**
     * @param array<string, list<string>> $capabilities name => origins
     */
    public function __construct(private array $capabilities)
    {
    }

    public function has(string $capability): bool
    {
        return isset($this->capabilities[$capability]);
    }

    public function all(): array
    {
        $names = array_map('strval', array_keys($this->capabilities));
        sort($names);

        return $names;
    }

    public function providers(string $capability): array
    {
        return $this->capabilities[$capability] ?? [];
    }
}
