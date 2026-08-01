<?php

declare(strict_types=1);

namespace Zana\Container\Resolution;

use Zana\Container\ServiceProvider;
use Zana\Container\Exception\ResolutionException;

/**
 * Resolves constructor parameters and creates class instances.
 * 
 * Responsible for:
 * - Inspecting constructor metadata
 * - Resolving each parameter through the ParameterResolver
 * - Instantiating the class with resolved arguments
 * - Caching constructor metadata for performance
 */
class ConstructorResolver
{
    /** @var array<string, \ReflectionMethod|null> */
    private static array $constructorCache = [];

    /** @var array<string, list<\ReflectionParameter>> */
    private static array $parameterCache = [];

    /**
     * Create an instance of the given class by resolving its constructor dependencies.
     *
     * @param string $class The fully-qualified class name
     * @param ServiceProvider $provider The service provider for dependency resolution
     * @return object A new instance of the class
     * @throws ResolutionException If constructor parameters cannot be resolved
     */
    public function resolve(string $class, ServiceProvider $provider): object
    {
        $ref = $this->getReflectionClass($class);
        $constructor = $this->getConstructor($ref);

        // No constructor — instantiate directly
        if ($constructor === null) {
            return $ref->newInstance();
        }

        $params = $this->getParameters($ref, $constructor);
        $args = [];

        $parameterResolver = new ParameterResolver();

        foreach ($params as $param) {
            $args[] = $parameterResolver->resolve($class, $param, $provider);
        }

        return $ref->newInstanceArgs($args);
    }

    /**
     * Get or create a ReflectionClass for the given class name.
     */
    private function getReflectionClass(string $class): \ReflectionClass
    {
        if (!class_exists($class)) {
            throw new ResolutionException("Class '{$class}' does not exist.");
        }
        return new \ReflectionClass($class);
    }

    /**
     * Get the constructor method, using cache if available.
     */
    private function getConstructor(\ReflectionClass $ref): ?\ReflectionMethod
    {
        $className = $ref->getName();

        if (!isset(self::$constructorCache[$className])) {
            self::$constructorCache[$className] = $ref->getConstructor();
        }

        return self::$constructorCache[$className];
    }

    /**
     * Get constructor parameters, using cache if available.
     *
     * @return list<\ReflectionParameter>
     */
    private function getParameters(\ReflectionClass $ref, \ReflectionMethod $constructor): array
    {
        $className = $ref->getName();

        if (!isset(self::$parameterCache[$className])) {
            self::$parameterCache[$className] = $constructor->getParameters();
        }

        return self::$parameterCache[$className];
    }

    /**
     * Clear the constructor and parameter caches.
     * Useful in test environments or when dynamically loading classes.
     */
    public static function clearCache(): void
    {
        self::$constructorCache = [];
        self::$parameterCache = [];
    }
}

