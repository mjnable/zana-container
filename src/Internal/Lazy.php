<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Native PHP lazy proxies for lazy services (specification §9.2).
 *
 * @internal
 */
final class Lazy
{
    /**
     * Why instances of the class cannot be lazy, or null if they can.
     *
     * PHP cannot make internal classes or their subclasses lazy. A class
     * without instance properties has no state to trigger initialisation, so
     * its constructor would never run.
     *
     * @param \ReflectionClass<object> $class
     */
    public static function unsupportedReason(\ReflectionClass $class): ?string
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if ($current->isInternal()) {
                return 'PHP cannot make internal classes or their subclasses lazy';
            }
        }

        $instanceProperties = array_filter(
            $class->getProperties(),
            static fn (\ReflectionProperty $property): bool => !$property->isStatic()
        );
        if ($instanceProperties === []) {
            return 'it has no instance properties, so PHP would never initialise it and its constructor would never run';
        }

        return null;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param \Closure(): T $factory Creates the real instance on first use
     * @return T
     */
    public static function proxy(string $class, \Closure $factory): object
    {
        return new \ReflectionClass($class)->newLazyProxy(static fn (object $proxy): object => $factory());
    }

    public static function isUninitialized(object $object): bool
    {
        return new \ReflectionClass($object)->isUninitializedLazyObject($object);
    }
}
