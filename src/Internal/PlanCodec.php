<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Converts construction plans to plain arrays for compiled containers, and back.
 *
 * @internal
 *
 * @phpstan-type TargetData array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: ?int}
 * @phpstan-type PlanData array{class: class-string, inner: int, parameters: list<TargetData>, properties: list<TargetData>, methods: list<array{name: string, parameters: list<TargetData>}>}
 */
final class PlanCodec
{
    /**
     * @return PlanData
     */
    public static function encode(ConstructorPlan $plan): array
    {
        return [
            'class' => $plan->class,
            'inner' => $plan->innerParameters,
            'parameters' => array_map(self::encodeTarget(...), $plan->parameters),
            'properties' => array_map(self::encodeTarget(...), $plan->properties),
            'methods' => array_map(
                static fn (MethodPlan $method): array => [
                    'name' => $method->method->getName(),
                    'parameters' => array_map(self::encodeTarget(...), $method->parameters),
                ],
                $plan->methods
            ),
        ];
    }

    /**
     * @param PlanData $data
     */
    public static function decode(array $data): ConstructorPlan
    {
        $class = $data['class'];

        return new ConstructorPlan(
            $class,
            array_map(static fn (array $target): ParameterPlan => self::decodeTarget($target, new \ReflectionParameter([$class, '__construct'], $target[1])), $data['parameters']),
            array_map(static fn (array $target): ParameterPlan => self::decodeTarget($target, new \ReflectionProperty($class, $target[1])), $data['properties']),
            array_map(
                static fn (array $method): MethodPlan => new MethodPlan(
                    new \ReflectionMethod($class, $method['name']),
                    array_map(static fn (array $target): ParameterPlan => self::decodeTarget($target, new \ReflectionParameter([$class, $method['name']], $target[1])), $method['parameters'])
                ),
                $data['methods']
            ),
            [],
            $data['inner'],
        );
    }

    /**
     * @return TargetData
     */
    private static function encodeTarget(ParameterPlan $plan): array
    {
        return [$plan->source->name, $plan->name(), $plan->service, $plan->key, $plan->tag, $plan->provider];
    }

    /**
     * @param TargetData $data
     */
    private static function decodeTarget(array $data, \ReflectionParameter|\ReflectionProperty $target): ParameterPlan
    {
        return new ParameterPlan(
            constant(ParameterSource::class . '::' . $data[0]),
            $target,
            $data[2],
            $data[3],
            $data[4],
            $data[5],
        );
    }
}
