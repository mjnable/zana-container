<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Zana\Container\Contracts\ServiceResolverInterface;

/**
 * Builds constructor plans by reflection, applying the parameter rules of the
 * container specification §6.2.
 *
 * @internal
 */
final class PlanBuilder
{
    private const RESOLVER_TYPES = [PsrContainerInterface::class, ServiceResolverInterface::class];

    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param \ReflectionClass<object> $class A class already checked to be instantiable
     * @param string|null $decorated For decorators: the decorated service, whose instance fills
     *                               the parameter typed with it
     */
    public function build(\ReflectionClass $class, ?string $decorated = null): ConstructorPlan
    {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return new ConstructorPlan($class->getName(), []);
        }

        $parameters = [];
        $problems = [];
        $inner = 0;

        foreach ($constructor->getParameters() as $parameter) {
            $plan = $this->plan($parameter, $decorated);
            if ($plan === null) {
                $problems[] = $this->describeProblem($parameter);
                continue;
            }

            if ($plan->source === ParameterSource::Inner) {
                $inner++;
            }
            $parameters[] = $plan;
        }

        return new ConstructorPlan($class->getName(), $parameters, $problems, $inner);
    }

    private function plan(\ReflectionParameter $parameter, ?string $decorated): ?ParameterPlan
    {
        $type = $parameter->getType();
        $class = $this->singleClassType($parameter);

        if ($class !== null && $decorated !== null && is_a($decorated, $class, true)) {
            return new ParameterPlan(ParameterSource::Inner, $parameter);
        }

        if ($class !== null && in_array($class, self::RESOLVER_TYPES, true)) {
            return new ParameterPlan(ParameterSource::Resolver, $parameter);
        }

        if ($parameter->isVariadic()) {
            return $class === null ? null : new ParameterPlan(ParameterSource::Variadic, $parameter, $class);
        }

        if ($class !== null && $this->registry->isResolvable($class)) {
            return new ParameterPlan(ParameterSource::Service, $parameter, $class);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return new ParameterPlan(ParameterSource::Default, $parameter);
        }

        if ($type !== null && $type->allowsNull()) {
            return new ParameterPlan(ParameterSource::Null, $parameter);
        }

        return null;
    }

    /**
     * The class or interface name when the parameter has exactly one non-builtin type.
     */
    private function singleClassType(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        $declaring = $parameter->getDeclaringClass();

        return match (strtolower($name)) {
            'self', 'static' => $declaring?->getName(),
            'parent' => ($declaring?->getParentClass() ?: null)?->getName(),
            default => $name,
        };
    }

    private function describeProblem(\ReflectionParameter $parameter): string
    {
        $name = '$' . $parameter->getName();
        $type = $parameter->getType();

        if ($type === null) {
            return "constructor parameter {$name} has no type, no default value and cannot be null";
        }

        $class = $this->singleClassType($parameter);
        if ($class !== null) {
            return "constructor parameter {$name}: {$class} is not registered";
        }

        if ($parameter->isVariadic()) {
            return "constructor parameter {$name} is variadic but has no class type";
        }

        return sprintf(
            'constructor parameter %s of type %s needs a default value, a nullable type, or a factory registration',
            $name,
            (string) $type
        );
    }
}
