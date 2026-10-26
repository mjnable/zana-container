<?php

declare(strict_types=1);

namespace Zana\Container\Graph;

/**
 * A dependency from one node to another.
 */
final readonly class DependencyEdge
{
    /**
     * @param int $from Id of the depending node
     * @param int $to Id of the node depended on
     * @param string $kind "constructor", "property", "method", "variadic", "tagged", "alias", "decorator" or "factory" (declared with uses())
     * @param string|null $parameter Parameter or property name, if any
     * @param bool $lazy Whether the target is a lazy service
     */
    public function __construct(
        public int $from,
        public int $to,
        public string $kind,
        public ?string $parameter,
        public bool $lazy,
    ) {
    }

    /**
     * @return array{from: int, to: int, kind: string, parameter: ?string, lazy: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'kind' => $this->kind,
            'parameter' => $this->parameter,
            'lazy' => $this->lazy,
        ];
    }
}
