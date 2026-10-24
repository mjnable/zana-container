<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Zana\Container\Attribute\Inject;
use Zana\Container\Attribute\Tagged;
use Zana\Container\Attribute\ValueAttribute;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\Contracts\ServiceResolverInterface;

/**
 * Builds resolution plans by reflection, applying the rules of the container
 * specification §6.2 (parameters), §6.3 (union and intersection types) and
 * §7 (property and method injection).
 *
 * @internal
 */
final class PlanBuilder
{
    private const RESOLVER_TYPES = [PsrContainerInterface::class, ServiceResolverInterface::class];

    /**
     * @param list<ParameterValueProviderInterface> $providers
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $providers,
    ) {
    }

    /**
     * @param \ReflectionClass<object> $class A class already checked to be instantiable
     * @param string|null $decorated For decorators: the decorated service, whose instance fills
     *                               the parameter typed with it
     */
    public function forClass(\ReflectionClass $class, ?string $decorated = null): ConstructorPlan
    {
        $problems = [];
        $inner = 0;
        $parameters = [];

        $constructor = $class->getConstructor();
        if ($constructor !== null) {
            $parameters = $this->parameters($constructor, 'constructor parameter', $problems, $decorated);
            foreach ($parameters as $parameter) {
                if ($parameter->source === ParameterSource::Inner) {
                    $inner++;
                }
            }
        }

        return new ConstructorPlan(
            $class->getName(),
            $parameters,
            $this->properties($class, $problems),
            $this->methods($class, $problems),
            $problems,
            $inner,
        );
    }

    /**
     * Plan the parameters of a callable for call(). Parameters named in $overrides
     * take the caller's value.
     *
     * @param list<string> $overrides
     * @return array{list<ParameterPlan>, list<array{string, string}>}
     */
    public function forFunction(\ReflectionFunctionAbstract $function, array $overrides): array
    {
        $problems = [];
        $plans = $this->parameters($function, 'parameter', $problems, null, $overrides);

        return [$plans, $problems];
    }

    /**
     * @param list<array{string, string}> $problems
     * @param list<string> $overrides
     * @return list<ParameterPlan>
     */
    private function parameters(\ReflectionFunctionAbstract $function, string $role, array &$problems, ?string $decorated, array $overrides = []): array
    {
        $plans = [];
        foreach ($function->getParameters() as $parameter) {
            if (in_array($parameter->getName(), $overrides, true)) {
                $plans[] = new ParameterPlan(ParameterSource::Override, $parameter);
                continue;
            }

            $plan = $this->plan($parameter, "{$role} \${$parameter->getName()}", $decorated);
            if (is_array($plan)) {
                $problems[] = $plan;
            } else {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Properties carrying #[Inject], #[Tagged] or a value attribute.
     *
     * @param \ReflectionClass<object> $class
     * @param list<array{string, string}> $problems
     * @return list<ParameterPlan>
     */
    private function properties(\ReflectionClass $class, array &$problems): array
    {
        $plans = [];
        foreach ($class->getProperties() as $property) {
            // Promoted properties inherit their parameter's attributes but are set by the constructor.
            if ($property->isPromoted() || !$this->isInjected($property)) {
                continue;
            }

            $what = "property \${$property->getName()}";
            $invalid = match (true) {
                $property->isStatic() => 'is static',
                $property->isReadOnly() => 'is readonly',
                !$property->hasType() => 'has no type',
                default => null,
            };
            if ($invalid !== null) {
                $problems[] = ['B11', "injected {$what} {$invalid}; injected properties must be typed, non-static and not readonly"];
                continue;
            }

            $plan = $this->plan($property, $what, null);
            if (is_array($plan)) {
                $problems[] = $plan;
            } else {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<array{string, string}> $problems
     * @return list<MethodPlan>
     */
    private function methods(\ReflectionClass $class, array &$problems): array
    {
        $plans = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getAttributes(Inject::class) === [] || $method->isConstructor()) {
                continue;
            }

            if ($method->isStatic()) {
                $problems[] = ['B11', "#[Inject] method {$method->getName()}() is static"];
                continue;
            }

            $plans[] = new MethodPlan(
                $method,
                $this->parameters($method, "method {$method->getName()}() parameter", $problems, null)
            );
        }

        return $plans;
    }

    /**
     * @return ParameterPlan|array{string, string} The plan, or a rule code and message
     */
    private function plan(\ReflectionParameter|\ReflectionProperty $target, string $what, ?string $decorated): ParameterPlan|array
    {
        $type = $target->getType();
        $class = $this->singleClassType($target);
        $isParameter = $target instanceof \ReflectionParameter;

        if ($isParameter && $class !== null && $decorated !== null && is_a($decorated, $class, true)) {
            return new ParameterPlan(ParameterSource::Inner, $target);
        }

        // 1. #[Inject(id, key)]. On a property, a bare #[Inject] only marks it for
        //    injection and resolution continues with the other steps.
        $inject = $this->attribute($target, Inject::class);
        if ($inject !== null && !$isParameter && $inject->id === null && $inject->key === null) {
            $inject = null;
        }
        if ($inject !== null) {
            $id = $inject->id ?? $class;
            if ($id === null) {
                return ['B9', "{$what} has #[Inject] without an id and has no class type"];
            }
            if ($inject->key !== null) {
                return $this->registry->isRegistered($id, $inject->key)
                    ? new ParameterPlan(ParameterSource::Keyed, $target, $id, $inject->key)
                    : ['B9', "{$what}: #[Inject] target {$id} [key: {$inject->key}] is not registered"];
            }

            return $this->registry->isResolvable($id)
                ? new ParameterPlan(ParameterSource::Service, $target, $id)
                : ['B9', "{$what}: #[Inject] target {$id} is not registered"];
        }

        // 2. #[Tagged(tag)]
        $tagged = $this->attribute($target, Tagged::class);
        if ($tagged !== null) {
            return $this->isIterableType($type)
                ? new ParameterPlan(ParameterSource::Tagged, $target, tag: $tagged->tag)
                : ['B11', "{$what} has #[Tagged] but is not typed array or iterable"];
        }

        // 3. parameter value providers
        foreach ($this->providers as $index => $provider) {
            if ($provider->supports($target)) {
                return new ParameterPlan(ParameterSource::Provider, $target, provider: $index);
            }
        }

        $valueAttribute = $target->getAttributes(ValueAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($valueAttribute !== null) {
            $short = substr((string) strrchr('\\' . $valueAttribute->getName(), '\\'), 1);

            return ['B11', "{$what} has #[{$short}] but no registered parameter value provider supports it"];
        }

        // 4. the owning resolver
        if ($class !== null && in_array($class, self::RESOLVER_TYPES, true)) {
            return new ParameterPlan(ParameterSource::Resolver, $target);
        }

        // 8. variadics (checked first so a registered type still collects every registration)
        if ($isParameter && $target->isVariadic()) {
            return $class === null
                ? ['B3', "{$what} is variadic but has no class type"]
                : new ParameterPlan(ParameterSource::Variadic, $target, $class);
        }

        // 6. a single registered class or interface
        if ($class !== null && $this->registry->isResolvable($class)) {
            return new ParameterPlan(ParameterSource::Service, $target, $class);
        }

        // 7. union, intersection and DNF types
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $candidates = $this->candidates($type);
            if (count($candidates) === 1) {
                return new ParameterPlan(ParameterSource::Service, $target, $candidates[0]);
            }
            if (count($candidates) > 1) {
                return ['B10', sprintf(
                    '%s of type %s is ambiguous: %s are all registered; pick one with #[Inject]',
                    $what,
                    (string) $type,
                    implode(', ', $candidates)
                )];
            }
        }

        // 9. default value, then null
        if ($isParameter ? $target->isDefaultValueAvailable() : $target->hasDefaultValue()) {
            return new ParameterPlan(ParameterSource::Default, $target);
        }

        if ($type !== null && $type->allowsNull()) {
            return new ParameterPlan(ParameterSource::Null, $target);
        }

        return ['B3', $this->describeProblem($target, $what, $class)];
    }

    /**
     * Registered services that satisfy a union, intersection or DNF type (§6.3).
     *
     * @return list<string>
     */
    private function candidates(\ReflectionUnionType|\ReflectionIntersectionType $type): array
    {
        $alternatives = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $candidates = [];

        foreach ($alternatives as $alternative) {
            if ($alternative instanceof \ReflectionNamedType) {
                if (!$alternative->isBuiltin() && $this->registry->isResolvable($alternative->getName())) {
                    $candidates[] = $alternative->getName();
                }
                continue;
            }

            $members = array_values(array_map(
                static fn (\ReflectionType $member): string => $member instanceof \ReflectionNamedType ? $member->getName() : (string) $member,
                $alternative->getTypes()
            ));
            foreach ($members as $member) {
                if ($this->registry->isResolvable($member) && $this->satisfiesAll($member, $members)) {
                    $candidates[] = $member;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Whether what a service resolves to satisfies every listed type.
     *
     * @param list<string> $types
     */
    private function satisfiesAll(string $service, array $types): bool
    {
        $index = $this->registry->last($this->registry->resolveAlias($service));
        if ($index === null) {
            return false;
        }

        $descriptor = $this->registry->descriptor($index);
        foreach ($types as $type) {
            $ok = match (true) {
                $descriptor->instance !== null => $descriptor->instance instanceof $type,
                $descriptor->implementation !== null => is_a($descriptor->implementation, $type, true),
                default => is_a($descriptor->service, $type, true),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function isInjected(\ReflectionProperty $property): bool
    {
        return $property->getAttributes(Inject::class) !== []
            || $property->getAttributes(Tagged::class) !== []
            || $property->getAttributes(ValueAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    /**
     * @template T of object
     * @param class-string<T> $attribute
     * @return T|null
     */
    private function attribute(\ReflectionParameter|\ReflectionProperty $target, string $attribute): ?object
    {
        $attributes = $target->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function isIterableType(?\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType && in_array($type->getName(), ['array', 'iterable'], true);
    }

    /**
     * The class or interface name when the target has exactly one non-builtin type.
     */
    private function singleClassType(\ReflectionParameter|\ReflectionProperty $target): ?string
    {
        $type = $target->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $declaring = $target->getDeclaringClass();

        return match (strtolower($type->getName())) {
            'self', 'static' => $declaring?->getName(),
            'parent' => ($declaring?->getParentClass() ?: null)?->getName(),
            default => $type->getName(),
        };
    }

    private function describeProblem(\ReflectionParameter|\ReflectionProperty $target, string $what, ?string $class): string
    {
        $type = $target->getType();

        if ($type === null) {
            return "{$what} has no type, no default value and cannot be null";
        }

        if ($class !== null) {
            return "{$what}: {$class} is not registered";
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return "{$what}: no registered service satisfies {$type}";
        }

        return sprintf(
            '%s of type %s needs a default value, a nullable type, a value attribute or a factory registration',
            $what,
            (string) $type
        );
    }
}
