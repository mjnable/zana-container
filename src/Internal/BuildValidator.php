<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Closure;
use Zana\Container\BuildError;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

/**
 * Validates a registry without creating any service (container specification
 * §10, rules B1 to B12) and builds the plans used at runtime.
 *
 * Factory and instance registrations are opaque: their own dependencies are
 * only checked when they are resolved.
 *
 * @internal
 */
final class BuildValidator
{
    private readonly PlanBuilder $planner;

    /** @var list<BuildError> */
    private array $errors = [];

    /** @var array<class-string, ConstructorPlan> */
    private array $plans = [];

    /** @var array<string, ConstructorPlan> */
    private array $decoratorPlans = [];

    /**
     * @param list<\Zana\Container\Contracts\ParameterValueProviderInterface> $providers
     */
    public function __construct(private readonly Registry $registry, array $providers)
    {
        $this->planner = new PlanBuilder($registry, $providers);
    }

    public function validate(): BuildResult
    {
        foreach ($this->registry->descriptors as $descriptor) {
            $this->checkDescriptor($descriptor);
        }

        $this->checkAliases();
        $this->checkTags();
        $this->checkDecorators();
        $this->checkCycles();
        $this->checkCaptiveDependencies();

        return new BuildResult($this->errors, $this->plans, $this->decoratorPlans);
    }

    // ── B1, B2, B3 ─────────────────────────────────────────────────────────

    private function checkDescriptor(ServiceDescriptor $descriptor): void
    {
        if ($descriptor->instance !== null) {
            if ($this->isType($descriptor->service) && !$descriptor->instance instanceof $descriptor->service) {
                $this->error('B2', $descriptor, sprintf(
                    'the registered instance is a %s, which is not a %s',
                    get_debug_type($descriptor->instance),
                    $descriptor->service
                ));
            }

            return;
        }

        $implementation = $descriptor->implementation;
        if ($implementation === null) {
            return; // factory
        }

        $class = $this->instantiableClass($implementation, $descriptor, 'B1');
        if ($class === null) {
            return;
        }

        if ($this->isType($descriptor->service) && !is_a($class->getName(), $descriptor->service, true)) {
            $this->error('B2', $descriptor, "{$class->getName()} does not implement or extend {$descriptor->service}");
        }

        $lazyProblem = $descriptor->lazy ? Lazy::unsupportedReason($class) : null;
        if ($lazyProblem !== null) {
            $this->error('B12', $descriptor, "cannot be lazy: {$lazyProblem}");
        }

        $plan = $this->plans[$class->getName()] ??= $this->planner->forClass($class);
        foreach ($plan->problems as [$code, $problem]) {
            $this->error($code, $descriptor, $problem);
        }
    }

    /**
     * @return \ReflectionClass<object>|null
     */
    private function instantiableClass(string $class, ServiceDescriptor $descriptor, string $code, string $role = ''): ?\ReflectionClass
    {
        try {
            $exists = class_exists($class);
        } catch (\Throwable $e) {
            $this->error($code, $descriptor, "{$role}{$class} could not be loaded: {$e->getMessage()}");

            return null;
        }

        if (!$exists) {
            $problem = match (true) {
                interface_exists($class) => "{$role}{$class} is an interface; register a class that implements it",
                trait_exists($class) => "{$role}{$class} is a trait, not a class",
                default => "{$role}class {$class} does not exist",
            };
            $this->error($code, $descriptor, $problem);

            return null;
        }

        $reflection = new \ReflectionClass($class);

        $problem = match (true) {
            $reflection->isEnum() => "{$role}{$class} is an enum; register its cases with instance()",
            $reflection->isAbstract() => "{$role}{$class} is abstract",
            $reflection->getConstructor()?->isPublic() === false => "{$role}{$class} has a non-public constructor; use a factory",
            default => null,
        };

        if ($problem !== null) {
            $this->error($code, $descriptor, $problem);

            return null;
        }

        return $reflection;
    }

    // ── B6, B7 ─────────────────────────────────────────────────────────────

    private function checkAliases(): void
    {
        $reportedCycles = [];

        foreach ($this->registry->aliases as $alias => $target) {
            if ($this->registry->isRegistered($alias)) {
                $this->errorFor('B6', $alias, "alias {$alias} is also registered as a service; remove one of them");
            }

            $path = [$alias];
            $current = $target;
            while (isset($this->registry->aliases[$current]) && !in_array($current, $path, true)) {
                $path[] = $current;
                $current = $this->registry->aliases[$current];
            }

            if (in_array($current, $path, true)) {
                $cycle = array_slice($path, (int) array_search($current, $path, true));
                $key = $this->cycleKey($cycle);
                if (!isset($reportedCycles[$key])) {
                    $reportedCycles[$key] = true;
                    $this->errorFor('B6', $alias, 'aliases form a cycle: ' . implode(' → ', [...$cycle, $current]));
                }
                continue;
            }

            if (!$this->registry->isRegistered($current)) {
                $this->errorFor('B6', $alias, "alias {$alias} points to {$current}, which is not registered");
            }
        }
    }

    private function checkTags(): void
    {
        foreach ($this->registry->tags as $tag => $services) {
            foreach ($services as $service) {
                if (!$this->registry->isResolvable($service)) {
                    $this->errorFor('B7', $service, "tag '{$tag}' contains {$service}, which is not registered");
                }
            }
        }
    }

    // ── B8 (and B3 for decorator parameters) ───────────────────────────────

    private function checkDecorators(): void
    {
        foreach ($this->registry->decorators as $service => $decorators) {
            $anchor = $this->anchorFor($service);

            if (!$this->registry->isRegisteredWithAnyKey($service)) {
                $hint = isset($this->registry->aliases[$service])
                    ? '; it is an alias, so decorate ' . $this->registry->resolveAlias($service) . ' instead'
                    : '';
                $this->errorFor('B8', $service, "decorated service {$service} is not registered{$hint}");
                continue;
            }

            foreach ($decorators as $decorator) {
                if ($decorator instanceof Closure) {
                    continue;
                }
                $this->checkClassDecorator($service, $decorator, $anchor);
            }
        }
    }

    private function checkClassDecorator(string $service, string $decorator, ServiceDescriptor $anchor): void
    {
        $role = "decorator {$decorator}: ";

        if (!$this->isType($service)) {
            $this->error('B8', $anchor, "{$role}class decorators need a class or interface service id; use a closure decorator for {$service}");

            return;
        }

        $class = $this->instantiableClass($decorator, $anchor, 'B8', $role);
        if ($class === null) {
            return;
        }

        if (!is_a($class->getName(), $service, true)) {
            $this->error('B8', $anchor, "{$role}it does not implement or extend {$service}");
        }

        $plan = $this->planner->forClass($class, $service);
        $this->decoratorPlans[BuildResult::decoratorKey($service, $decorator)] = $plan;

        if ($plan->innerParameters !== 1) {
            $this->error('B8', $anchor, sprintf(
                '%sits constructor must have exactly one parameter typed %s (or a supertype) to receive the decorated instance; found %d',
                $role,
                $service,
                $plan->innerParameters
            ));
        }

        foreach ($plan->problems as [$code, $problem]) {
            $this->error($code, $anchor, $role . $problem);
        }
    }

    // ── B4 ─────────────────────────────────────────────────────────────────

    private function checkCycles(): void
    {
        $state = []; // index => 1 visiting, 2 done
        $reported = [];

        foreach (array_keys($this->registry->descriptors) as $index) {
            $this->visit($index, [], $state, $reported);
        }
    }

    /**
     * @param list<int> $path
     * @param array<int, int> $state
     * @param array<string, true> $reported
     */
    private function visit(int $index, array $path, array &$state, array &$reported): void
    {
        if (($state[$index] ?? 0) === 2) {
            return;
        }

        if (($state[$index] ?? 0) === 1) {
            $cycle = array_slice($path, (int) array_search($index, $path, true));
            $key = $this->cycleKey(array_map('strval', $cycle));
            if (!isset($reported[$key])) {
                $reported[$key] = true;
                $labels = array_map(fn (int $i): string => Label::of($this->registry->descriptor($i)), [...$cycle, $index]);
                $this->error('B4', $this->registry->descriptor($cycle[0]), 'circular dependency: ' . implode(' → ', $labels));
            }

            return;
        }

        $state[$index] = 1;
        $path[] = $index;
        foreach ($this->edges($index) as $target) {
            if ($this->registry->descriptor($target)->lazy) {
                continue; // a lazy service is created later, so it breaks the cycle
            }
            $this->visit($target, $path, $state, $reported);
        }
        $state[$index] = 2;
    }

    // ── B5 ─────────────────────────────────────────────────────────────────

    private function checkCaptiveDependencies(): void
    {
        foreach ($this->registry->descriptors as $index => $descriptor) {
            if ($descriptor->lifetime !== ServiceLifetime::Singleton) {
                continue;
            }

            $seen = [$index => true];
            $reported = [];
            $this->findScoped($index, [$index], $seen, $reported);
        }
    }

    /**
     * Walk through transient dependencies of a singleton looking for scoped ones.
     *
     * @param list<int> $path
     * @param array<int, true> $seen
     * @param array<int, true> $reported
     */
    private function findScoped(int $index, array $path, array &$seen, array &$reported): void
    {
        foreach ($this->edges($index) as $target) {
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;

            $lifetime = $this->registry->descriptor($target)->lifetime;
            if ($lifetime === ServiceLifetime::Scoped && !isset($reported[$target])) {
                $reported[$target] = true;
                $labels = array_map(fn (int $i): string => Label::withLifetime($this->registry->descriptor($i)), [...$path, $target]);
                $this->error('B5', $this->registry->descriptor($path[0]), implode(' → ', $labels) . ': a singleton must not depend on a scoped service');
            } elseif ($lifetime === ServiceLifetime::Transient) {
                $this->findScoped($target, [...$path, $target], $seen, $reported);
            }
        }
    }

    // ── Dependency graph ───────────────────────────────────────────────────

    /**
     * Registrations a registration depends on: its constructor dependencies
     * (class strategy only) plus those of its service's class decorators.
     *
     * @return list<int>
     */
    private function edges(int $index): array
    {
        $descriptor = $this->registry->descriptor($index);
        $plans = [];

        if ($descriptor->implementation !== null && isset($this->plans[$descriptor->implementation])) {
            $plans[] = $this->plans[$descriptor->implementation];
        }

        foreach ($this->registry->decoratorsOf($descriptor->service) as $decorator) {
            if (is_string($decorator)) {
                $plan = $this->decoratorPlans[BuildResult::decoratorKey($descriptor->service, $decorator)] ?? null;
                if ($plan !== null) {
                    $plans[] = $plan;
                }
            }
        }

        $targets = [];
        foreach ($plans as $plan) {
            foreach ($plan->dependencies() as $dependency) {
                $services = match ($dependency->source) {
                    ParameterSource::Tagged => $this->registry->tagged((string) $dependency->tag),
                    default => [(string) $dependency->service],
                };

                foreach ($services as $service) {
                    if ($dependency->source === ParameterSource::Keyed) {
                        $target = $this->registry->last($service, $dependency->key);
                        if ($target !== null) {
                            $targets[] = $target;
                        }
                        continue;
                    }

                    $service = $this->registry->resolveAlias($service);
                    if ($dependency->source === ParameterSource::Variadic) {
                        array_push($targets, ...$this->registry->all($service));
                    } elseif (($target = $this->registry->last($service)) !== null) {
                        $targets[] = $target;
                    }
                }
            }
        }

        return $targets;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function isType(string $id): bool
    {
        try {
            return class_exists($id) || interface_exists($id);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The registration an error about a service id should point at, for its origin.
     */
    private function anchorFor(string $service): ServiceDescriptor
    {
        foreach ($this->registry->descriptors as $descriptor) {
            if ($descriptor->service === $service) {
                return $descriptor;
            }
        }

        return new ServiceDescriptor($service, ServiceLifetime::Transient, implementation: $service);
    }

    /**
     * @param list<string> $cycle
     */
    private function cycleKey(array $cycle): string
    {
        sort($cycle);

        return implode("\0", $cycle);
    }

    private function error(string $code, ServiceDescriptor $descriptor, string $problem): void
    {
        $this->errors[] = new BuildError(
            $code,
            Label::of($descriptor) . ': ' . $problem,
            $descriptor->service,
            $descriptor->key,
            $descriptor->origin,
        );
    }

    private function errorFor(string $code, string $service, string $problem): void
    {
        $this->errors[] = new BuildError($code, $problem, $service);
    }
}
