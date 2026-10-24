<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Closure;
use Zana\Container\Graph\DependencyEdge;
use Zana\Container\Graph\ServiceGraph;
use Zana\Container\Graph\ServiceNode;

/**
 * Builds the service graph from a registry and its construction plans
 * (specification §10.2). Registrations keep their registry index as node id;
 * aliases follow.
 *
 * @internal
 */
final class GraphBuilder
{
    /**
     * @param array<class-string, ConstructorPlan> $plans
     * @param array<string, ConstructorPlan> $decoratorPlans
     * @param array<string, list<string>> $capabilities
     */
    public static function build(Registry $registry, array $plans, array $decoratorPlans, array $capabilities): ServiceGraph
    {
        $tagsOf = [];
        foreach ($registry->tags as $tag => $services) {
            foreach ($services as $service) {
                $tagsOf[$service][] = $tag;
            }
        }

        $nodes = [];
        foreach ($registry->descriptors as $index => $descriptor) {
            $nodes[] = new ServiceNode(
                $index,
                $descriptor->service,
                $descriptor->key,
                Label::lifetime($descriptor->lifetime),
                match (true) {
                    $descriptor->instance !== null => 'instance',
                    $descriptor->factory !== null => 'factory',
                    default => 'class',
                },
                $descriptor->implementation,
                $descriptor->origin,
                $descriptor->key === null ? ($tagsOf[$descriptor->service] ?? []) : [],
                array_map(
                    static fn (string|Closure $decorator): string => $decorator instanceof Closure ? 'closure' : $decorator,
                    $registry->decoratorsOf($descriptor->service)
                ),
                $descriptor->lazy,
            );
        }

        $aliasNodes = [];
        foreach ($registry->aliases as $alias => $target) {
            $id = count($nodes);
            $aliasNodes[$alias] = $id;
            $nodes[] = new ServiceNode($id, $alias, null, null, 'alias', $target, null, $tagsOf[$alias] ?? [], [], false);
        }

        $edges = [];
        $resolveTarget = static fn (string $id): ?int => $aliasNodes[$id] ?? $registry->last($id);

        foreach ($registry->descriptors as $index => $descriptor) {
            if ($descriptor->implementation !== null && isset($plans[$descriptor->implementation])) {
                $plan = $plans[$descriptor->implementation];
                self::planEdges($index, $plan->parameters, 'constructor', $registry, $resolveTarget, $edges);
                self::planEdges($index, $plan->properties, 'property', $registry, $resolveTarget, $edges);
                foreach ($plan->methods as $method) {
                    self::planEdges($index, $method->parameters, 'method', $registry, $resolveTarget, $edges);
                }
            }

            foreach ($registry->decoratorsOf($descriptor->service) as $decorator) {
                $plan = is_string($decorator) ? ($decoratorPlans[BuildResult::decoratorKey($descriptor->service, $decorator)] ?? null) : null;
                if ($plan !== null) {
                    self::planEdges($index, $plan->parameters, 'decorator', $registry, $resolveTarget, $edges);
                }
            }
        }

        foreach ($registry->aliases as $alias => $target) {
            $to = $resolveTarget($target);
            if ($to !== null) {
                $edges[] = new DependencyEdge($aliasNodes[$alias], $to, 'alias', null, $nodes[$to]->lazy);
            }
        }

        return new ServiceGraph($nodes, $edges, $capabilities);
    }

    /**
     * @param list<ParameterPlan> $plans
     * @param Closure(string): ?int $resolveTarget
     * @param list<DependencyEdge> $edges
     */
    private static function planEdges(int $from, array $plans, string $kind, Registry $registry, Closure $resolveTarget, array &$edges): void
    {
        foreach ($plans as $plan) {
            $targets = match ($plan->source) {
                ParameterSource::Service => [[$resolveTarget((string) $plan->service), $kind]],
                ParameterSource::Keyed => [[$registry->last((string) $plan->service, $plan->key), $kind]],
                ParameterSource::Variadic => array_map(
                    static fn (int $i): array => [$i, 'variadic'],
                    $registry->all($registry->resolveAlias((string) $plan->service))
                ),
                ParameterSource::Tagged => array_map(
                    static fn (string $member): array => [$resolveTarget($member), 'tagged'],
                    $registry->tagged((string) $plan->tag)
                ),
                default => [],
            };

            foreach ($targets as [$to, $edgeKind]) {
                if ($to !== null) {
                    $lazy = $to < count($registry->descriptors) && $registry->descriptor($to)->lazy;
                    $edges[] = new DependencyEdge($from, $to, $edgeKind, $plan->name(), $lazy);
                }
            }
        }
    }
}
