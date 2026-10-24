<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Closure;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\Contracts\ResolutionInterceptorInterface;
use Zana\Container\Contracts\ScopeInterface;
use Zana\Container\Contracts\ScopeObserverInterface;
use Zana\Container\Exception\CircularDependencyException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ResolutionContext;
use Zana\Container\ScopeMetadata;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

/**
 * Creates service instances for the root container and its scopes.
 *
 * Applies the lifetime and owning-resolver rules (specification §6.2, §8),
 * runs constructors, factories and injection, applies decorators, and detects
 * circular resolution at runtime. Every resolver of one container shares one engine.
 *
 * @internal
 */
final class Engine
{
    /** @var list<int> Descriptor indexes being resolved outside any fiber */
    private array $mainStack = [];

    /** @var \WeakMap<object, list<int>> Fiber => indexes being resolved in that fiber */
    private \WeakMap $fiberStacks;

    private readonly PlanBuilder $planner;

    /**
     * @param array<class-string, ConstructorPlan> $plans
     * @param array<string, ConstructorPlan> $decoratorPlans
     * @param list<ParameterValueProviderInterface> $providers
     * @param list<ResolutionInterceptorInterface> $interceptors First is outermost
     * @param list<ScopeObserverInterface> $observers
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $plans,
        private readonly array $decoratorPlans,
        private readonly array $providers,
        private readonly array $interceptors = [],
        private readonly array $observers = [],
    ) {
        $this->fiberStacks = new \WeakMap();
        $this->planner = new PlanBuilder($registry, $providers);
    }

    public function scopeCreated(ScopeInterface $scope): void
    {
        foreach ($this->observers as $observer) {
            $observer->scopeCreated($scope);
        }
    }

    /**
     * @return list<\Throwable>
     */
    public function scopeDisposing(ScopeInterface $scope): array
    {
        $errors = [];
        foreach ($this->observers as $observer) {
            try {
                $observer->scopeDisposing($scope);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /**
     * @return list<\Throwable>
     */
    public function scopeDisposed(ScopeMetadata $metadata): array
    {
        $errors = [];
        foreach ($this->observers as $observer) {
            try {
                $observer->scopeDisposed($metadata);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $errors;
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
     * Invoke a callable with its parameters resolved from $resolver (specification §7.3).
     *
     * Exceptions thrown by the callable itself are not wrapped.
     *
     * @param callable|array<mixed>|string $callable
     * @param array<array-key, mixed> $arguments Named overrides
     */
    public function call(callable|array|string $callable, array $arguments, AbstractResolver $resolver): mixed
    {
        foreach (array_keys($arguments) as $name) {
            if (is_int($name)) {
                throw new ContainerException('call() accepts named arguments only; got a positional argument at index ' . $name . '.');
            }
        }

        [$function, $object, $label] = $this->reflectCallable($callable, $resolver);
        [$plans, $problems] = $this->planner->forFunction($function, array_map('strval', array_keys($arguments)));

        if ($problems !== []) {
            $messages = array_map(static fn (array $problem): string => "[{$problem[0]}] {$problem[1]}", $problems);
            throw new ResolutionException("Cannot call {$label}:\n  " . implode("\n  ", $messages) . $this->formattedChain());
        }

        $values = $this->arguments($plans, $resolver, null, null, $arguments);

        return $function instanceof \ReflectionMethod
            ? $function->invokeArgs($object, $values)
            : $function->invokeArgs($values);
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
                $instance = $descriptor->lazy
                    ? $this->lazyProxy($index, $descriptor, $owner)
                    : $this->activate($descriptor, $owner);
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

    /**
     * A proxy that runs the full creation pipeline on first use (specification §9.2).
     */
    private function lazyProxy(int $index, ServiceDescriptor $descriptor, AbstractResolver $owner): object
    {
        /** @var class-string $implementation */
        $implementation = $descriptor->implementation;

        return Lazy::proxy($implementation, function () use ($index, $descriptor, $owner): object {
            $stack = $this->stack();
            if (in_array($index, $stack, true)) {
                $cycle = array_map(fn (int $i): string => Label::of($this->registry->descriptor($i)), [...$stack, $index]);
                throw CircularDependencyException::forCycle($cycle);
            }

            $this->push($index);
            try {
                return $this->activate($descriptor, $owner);
            } finally {
                $this->pop();
            }
        });
    }

    /**
     * Create through the interceptors, outermost first (specification §9.1).
     */
    private function activate(ServiceDescriptor $descriptor, AbstractResolver $owner): object
    {
        $create = fn (): object => $this->instantiate($descriptor, $owner);

        foreach (array_reverse($this->interceptors) as $interceptor) {
            $next = $create;
            $create = function () use ($interceptor, $next, $descriptor, $owner): object {
                $result = $interceptor->intercept($this->context($descriptor, $owner), $next);
                if (!$this->satisfies($result, $descriptor->service)) {
                    throw ResolutionException::invalidResult(
                        'Interceptor ' . get_class($interceptor) . ' for ' . Label::of($descriptor),
                        $descriptor->service,
                        $result,
                        $this->chain()
                    );
                }

                return $result;
            };
        }

        try {
            return $create();
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ResolutionException::creationFailed(Label::of($descriptor), $this->chain(), $e);
        }
    }

    private function instantiate(ServiceDescriptor $descriptor, AbstractResolver $owner): object
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

            return $this->construct($this->plans[$implementation], $owner, $descriptor);
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
                : $this->construct($this->decoratorPlans[BuildResult::decoratorKey($descriptor->service, $decorator)], $owner, $descriptor, $inner);
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

    private function construct(ConstructorPlan $plan, AbstractResolver $owner, ServiceDescriptor $descriptor, ?object $inner = null): object
    {
        $class = $plan->class;
        $object = new $class(...$this->arguments($plan->parameters, $owner, $descriptor, $inner));

        foreach ($plan->properties as $property) {
            if ($property->source === ParameterSource::Default) {
                continue; // keep the declared default
            }

            /** @var \ReflectionProperty $target */
            $target = $property->target;
            $target->setValue($object, $this->value($property, $owner, $descriptor, $inner, []));
        }

        foreach ($plan->methods as $method) {
            $method->method->invokeArgs($object, $this->arguments($method->parameters, $owner, $descriptor, $inner));
        }

        return $object;
    }

    /**
     * @param list<ParameterPlan> $plans
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed>
     */
    private function arguments(array $plans, AbstractResolver $owner, ?ServiceDescriptor $descriptor, ?object $inner, array $overrides = []): array
    {
        $positional = ParameterPlan::needPositional($plans);
        $arguments = [];

        foreach ($plans as $plan) {
            if ($plan->source === ParameterSource::Variadic) {
                array_push($arguments, ...$owner->getAll((string) $plan->service));
                continue;
            }

            if ($plan->source === ParameterSource::Default && !$positional) {
                continue; // named arguments let PHP evaluate the default itself
            }

            $value = $this->value($plan, $owner, $descriptor, $inner, $overrides);
            if ($positional) {
                $arguments[] = $value;
            } else {
                $arguments[$plan->name()] = $value;
            }
        }

        return $arguments;
    }

    /**
     * @param array<array-key, mixed> $overrides
     */
    private function value(ParameterPlan $plan, AbstractResolver $owner, ?ServiceDescriptor $descriptor, ?object $inner, array $overrides): mixed
    {
        return match ($plan->source) {
            ParameterSource::Resolver => $owner,
            ParameterSource::Service => $owner->get((string) $plan->service),
            ParameterSource::Keyed => $owner->getKeyed((string) $plan->service, (string) $plan->key),
            ParameterSource::Tagged => $owner->tagged((string) $plan->tag),
            ParameterSource::Provider => $this->provided($plan, $descriptor, $owner),
            ParameterSource::Metadata => $owner->metadata(),
            ParameterSource::Inner => $inner,
            ParameterSource::Override => $overrides[$plan->name()],
            ParameterSource::Null => null,
            ParameterSource::Default => $plan->target->getDefaultValue(),
            ParameterSource::Variadic => throw new \LogicException('Variadic parameters are spread by arguments().'),
        };
    }

    /**
     * A provider's value; int or string values for a backed-enum target become the enum case.
     */
    private function provided(ParameterPlan $plan, ?ServiceDescriptor $descriptor, AbstractResolver $owner): mixed
    {
        $provider = $this->providers[(int) $plan->provider];
        $value = $provider->provide($plan->target, new ResolutionContext($descriptor, $this->chain(), $owner->metadata()));

        $type = $plan->target->getType();
        if ((is_int($value) || is_string($value))
            && $type instanceof \ReflectionNamedType
            && is_subclass_of($type->getName(), \BackedEnum::class)
        ) {
            /** @var class-string<\BackedEnum> $enum */
            $enum = $type->getName();

            return $enum::from($value);
        }

        return $value;
    }

    /**
     * @param callable|array<mixed>|string $callable
     * @return array{\ReflectionFunction|\ReflectionMethod, ?object, string}
     */
    private function reflectCallable(callable|array|string $callable, AbstractResolver $resolver): array
    {
        if ($callable instanceof Closure) {
            return [new \ReflectionFunction($callable), null, 'a closure'];
        }

        if (is_object($callable)) {
            return [new \ReflectionMethod($callable, '__invoke'), $callable, get_class($callable) . '::__invoke()'];
        }

        if (is_string($callable)) {
            if (!str_contains($callable, '::')) {
                return [new \ReflectionFunction($callable), null, "{$callable}()"];
            }
            $callable = explode('::', $callable, 2);
        }

        if (!is_array($callable)) {
            throw new ContainerException('call() expects a closure, an invokable object, "Class::method", [object, method] or [class, method].');
        }

        $target = $callable[0] ?? null;
        $method = $callable[1] ?? null;
        if (count($callable) !== 2 || !is_string($method) || (!is_object($target) && !is_string($target))) {
            throw new ContainerException('call() expects a closure, an invokable object, "Class::method", [object, method] or [class, method].');
        }
        $reflection = new \ReflectionMethod($target, $method);
        $class = is_object($target) ? get_class($target) : $target;
        $label = "{$class}::{$method}()";

        if (is_object($target)) {
            return [$reflection, $reflection->isStatic() ? null : $target, $label];
        }

        if ($reflection->isStatic()) {
            return [$reflection, null, $label];
        }

        $instance = $resolver->get($target);
        assert(is_object($instance));

        return [$reflection, $instance, $label];
    }

    private function context(ServiceDescriptor $descriptor, AbstractResolver $owner): ResolutionContext
    {
        return new ResolutionContext($descriptor, $this->chain(), $owner->metadata());
    }

    private function satisfies(object $instance, string $service): bool
    {
        if (!class_exists($service) && !interface_exists($service)) {
            return true; // plain string ids carry no type
        }

        return $instance instanceof $service;
    }

    private function formattedChain(): string
    {
        $chain = $this->chain();

        return $chain === [] ? '' : "\nResolution chain:\n  " . implode("\n  → ", $chain);
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
