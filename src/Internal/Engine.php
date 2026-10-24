<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Closure;
use Zana\Container\Exception\CircularDependencyException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

/**
 * Creates service instances for the root container and its scopes.
 *
 * Applies the lifetime and owning-resolver rules (specification §6.2, §8),
 * runs constructors and factories, applies decorators, and detects circular
 * resolution at runtime. Every resolver of one container shares one engine.
 *
 * @internal
 */
final class Engine
{
    /** @var list<int> Descriptor indexes being resolved outside any fiber */
    private array $mainStack = [];

    /** @var \WeakMap<object, list<int>> Fiber => indexes being resolved in that fiber */
    private \WeakMap $fiberStacks;

    /**
     * @param array<class-string, ConstructorPlan> $plans
     * @param array<string, ConstructorPlan> $decoratorPlans
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $plans,
        private readonly array $decoratorPlans,
    ) {
        $this->fiberStacks = new \WeakMap();
    }

    public function resolve(int $index, AbstractResolver $requester): object
    {
        $descriptor = $this->registry->descriptor($index);

        $owner = match ($descriptor->lifetime) {
            ServiceLifetime::Singleton => $requester->root(),
            ServiceLifetime::Transient => $requester,
            ServiceLifetime::Scoped => $requester->isScope()
                ? $requester
                : throw ScopeException::scopedFromRoot(Label::of($descriptor), $this->chain()),
        };

        if ($descriptor->lifetime === ServiceLifetime::Transient) {
            return $this->create($index, $descriptor, $owner, track: false);
        }

        return $owner->cached($index) ?? $owner->store($index, $this->create($index, $descriptor, $owner, track: true));
    }

    /**
     * Labels of the registrations currently being resolved, outermost first.
     *
     * @return list<string>
     */
    public function chain(): array
    {
        return array_map(
            fn (int $index): string => Label::of($this->registry->descriptor($index)),
            $this->stack()
        );
    }

    private function create(int $index, ServiceDescriptor $descriptor, AbstractResolver $owner, bool $track): object
    {
        $stack = $this->stack();
        $position = array_search($index, $stack, true);
        if ($position !== false) {
            $cycle = array_map(
                fn (int $i): string => Label::of($this->registry->descriptor($i)),
                [...array_slice($stack, $position), $index]
            );
            throw CircularDependencyException::forCycle($cycle);
        }

        $this->push($index);
        try {
            if ($descriptor->instance !== null) {
                $instance = $descriptor->instance;
            } else {
                $instance = $this->activate($descriptor, $owner);
                if ($track) {
                    $owner->track($instance);
                }
            }

            foreach ($this->registry->decoratorsOf($descriptor->service) as $decorator) {
                $instance = $this->decorate($descriptor, $decorator, $instance, $owner);
                if ($track) {
                    $owner->track($instance);
                }
            }

            return $instance;
        } finally {
            $this->pop();
        }
    }

    private function activate(ServiceDescriptor $descriptor, AbstractResolver $owner): object
    {
        try {
            if ($descriptor->factory !== null) {
                $instance = ($descriptor->factory)($owner);
                if (!is_object($instance) || !$this->satisfies($instance, $descriptor->service)) {
                    throw ResolutionException::invalidResult(
                        'The factory of ' . Label::of($descriptor),
                        $descriptor->service,
                        $instance,
                        $this->chain()
                    );
                }

                return $instance;
            }

            /** @var class-string $implementation */
            $implementation = $descriptor->implementation;

            return $this->construct($this->plans[$implementation], $owner);
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ResolutionException::creationFailed(Label::of($descriptor), $this->chain(), $e);
        }
    }

    /**
     * @param class-string|Closure $decorator
     */
    private function decorate(ServiceDescriptor $descriptor, string|Closure $decorator, object $inner, AbstractResolver $owner): object
    {
        $name = $decorator instanceof Closure ? 'a closure decorator' : "decorator {$decorator}";

        try {
            $result = $decorator instanceof Closure
                ? $decorator($inner, $owner)
                : $this->construct($this->decoratorPlans[BuildResult::decoratorKey($descriptor->service, $decorator)], $owner, $inner);
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ResolutionException::creationFailed("{$name} of " . Label::of($descriptor), $this->chain(), $e);
        }

        if (!is_object($result) || !$this->satisfies($result, $descriptor->service)) {
            throw ResolutionException::invalidResult(ucfirst($name) . ' of ' . Label::of($descriptor), $descriptor->service, $result, $this->chain());
        }

        return $result;
    }

    private function construct(ConstructorPlan $plan, AbstractResolver $owner, ?object $inner = null): object
    {
        $positional = $plan->isPositional();
        $arguments = [];

        foreach ($plan->parameters as $parameter) {
            if ($parameter->source === ParameterSource::Variadic) {
                array_push($arguments, ...$owner->getAll((string) $parameter->service));
                continue;
            }

            if ($parameter->source === ParameterSource::Default && !$positional) {
                continue; // named arguments let PHP evaluate the default itself
            }

            $value = match ($parameter->source) {
                ParameterSource::Resolver => $owner,
                ParameterSource::Service => $owner->get((string) $parameter->service),
                ParameterSource::Inner => $inner,
                ParameterSource::Null => null,
                ParameterSource::Default => $parameter->parameter->getDefaultValue(),
            };

            if ($positional) {
                $arguments[] = $value;
            } else {
                $arguments[$parameter->parameter->getName()] = $value;
            }
        }

        $class = $plan->class;

        return new $class(...$arguments);
    }

    private function satisfies(object $instance, string $service): bool
    {
        if (!class_exists($service) && !interface_exists($service)) {
            return true; // plain string ids carry no type
        }

        return $instance instanceof $service;
    }

    /**
     * @return list<int>
     */
    private function stack(): array
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? $this->mainStack : ($this->fiberStacks[$fiber] ?? []);
    }

    private function push(int $index): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainStack[] = $index;

            return;
        }

        $stack = $this->fiberStacks[$fiber] ?? [];
        $stack[] = $index;
        $this->fiberStacks[$fiber] = $stack;
    }

    private function pop(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            array_pop($this->mainStack);

            return;
        }

        $stack = $this->fiberStacks[$fiber] ?? [];
        array_pop($stack);
        $this->fiberStacks[$fiber] = $stack;
    }
}
