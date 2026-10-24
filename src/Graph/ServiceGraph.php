<?php

declare(strict_types=1);

namespace Zana\Container\Graph;

/**
 * Immutable dependency graph of a service collection, built without creating
 * any service. toArray() is the stable, JSON-encodable format consumed by
 * tooling; its schema only changes additively within a major version.
 *
 * @phpstan-type GraphData array{schema: int, nodes: list<array{id: int, service: string, key: ?string, lifetime: ?string, strategy: string, implementation: ?string, origin: ?string, tags: list<string>, decorators: list<string>, lazy: bool}>, edges: list<array{from: int, to: int, kind: string, parameter: ?string, lazy: bool}>, capabilities: array<string, list<string>>}
 */
final readonly class ServiceGraph
{
    public const SCHEMA = 1;

    /**
     * @param list<ServiceNode> $nodes
     * @param list<DependencyEdge> $edges
     * @param array<string, list<string>> $capabilities Capability => origins that provided it
     */
    public function __construct(
        private array $nodes,
        private array $edges,
        private array $capabilities,
    ) {
    }

    /**
     * @param GraphData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(static fn (array $n): ServiceNode => new ServiceNode(
                $n['id'],
                $n['service'],
                $n['key'],
                $n['lifetime'],
                $n['strategy'],
                $n['implementation'],
                $n['origin'],
                $n['tags'],
                $n['decorators'],
                $n['lazy'],
            ), $data['nodes']),
            array_map(static fn (array $e): DependencyEdge => new DependencyEdge($e['from'], $e['to'], $e['kind'], $e['parameter'], $e['lazy']), $data['edges']),
            $data['capabilities'],
        );
    }

    /**
     * @return list<ServiceNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<DependencyEdge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * @return array<string, list<string>>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Edges leaving a node.
     *
     * @return list<DependencyEdge>
     */
    public function dependenciesOf(int $node): array
    {
        return array_values(array_filter($this->edges, static fn (DependencyEdge $edge): bool => $edge->from === $node));
    }

    /**
     * @return GraphData
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'nodes' => array_map(static fn (ServiceNode $node): array => $node->toArray(), $this->nodes),
            'edges' => array_map(static fn (DependencyEdge $edge): array => $edge->toArray(), $this->edges),
            'capabilities' => $this->capabilities,
        ];
    }
}
