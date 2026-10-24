<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * Read-only view of the capabilities declared with ServiceCollection::provide().
 *
 * A capability is presence metadata only; it never resolves to an object.
 */
interface CapabilitiesInterface
{
    public function has(string $capability): bool;

    /**
     * @return list<string> Sorted capability names
     */
    public function all(): array;

    /**
     * @return list<string> Origins that provided the capability, in declaration order
     */
    public function providers(string $capability): array;
}
