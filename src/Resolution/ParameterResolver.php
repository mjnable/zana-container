<?php

declare(strict_types=1);

namespace Zana\Container\Resolution;

use Zana\Container\ServiceProvider;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\ResolutionException;

/**
 * Resolves individual constructor parameters for dependency injection.
 * 
 * Handles resolution strategies:
 * - Class/interface types (resolved from container)
 * - Built-in types (default values required)
 * - Nullable parameters (returns null if not registered)
 * - Parameters with default values
 * - Union/intersection types (falls back to default/null)
 */
class ParameterResolver
{
    /**
     * Resolve a single constructor parameter.
     *
     * @param string $class The class being constructed (for error reporting)
     * @param \ReflectionParameter $param The parameter to resolve
     * @param ServiceProvider $provider The service provider for resolving class types
     * @return mixed The resolved parameter value
     * @throws ResolutionException If the parameter cannot be resolved
     */
    public function resolve(string $class, \ReflectionParameter $param, ServiceProvider $provider): mixed
    {
        $type = $param->getType();

        // No type hint — rely on default value
        if ($type === null) {
            return $this->resolveUntyped($class, $param);
        }

        // Named type (class, interface, built-in like string, int, array)
        if ($type instanceof \ReflectionNamedType) {
            return $this->resolveNamedType($class, $param, $type, $provider);
        }

        // Union type (e.g., LoggerInterface|NullLogger) or intersection type
        return $this->resolveComplexType($class, $param);
    }

    /**
     * Resolve a parameter with no type hint.
     */
    private function resolveUntyped(string $class, \ReflectionParameter $param): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw ResolutionException::unresolvedDependency(
            $class,
            $param->getName(),
            'mixed (no type hint)'
        );
    }

    /**
     * Resolve a parameter with a named type (class, interface, or built-in).
     */
    private function resolveNamedType(
        string $class,
        \ReflectionParameter $param,
        \ReflectionNamedType $type,
        ServiceProvider $provider
    ): mixed {
        $typeName = $type->getName();

        // Built-in types (string, int, float, bool, array, callable, iterable, void, never, mixed)
        if ($type->isBuiltin()) {
            return $this->resolveBuiltinType($class, $param, $typeName);
        }

        // Class/interface/enum — resolve from container
        return $this->resolveClassType($class, $param, $typeName, $provider);
    }

    /**
     * Resolve a built-in type parameter (string, int, array, etc.).
     * These cannot be resolved from the container and require a default value.
     */
    private function resolveBuiltinType(string $class, \ReflectionParameter $param, string $typeName): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw ResolutionException::unresolvedDependency(
            $class,
            $param->getName(),
            $typeName
        );
    }

    /**
     * Resolve a class/interface/enum type from the container.
     */
    private function resolveClassType(
        string $class,
        \ReflectionParameter $param,
        string $typeName,
        ServiceProvider $provider
    ): mixed {
        try {
            return $provider->get($typeName);
        } catch (NotFoundException $e) {
            // Nullable parameter — return null if no binding exists
            if ($param->allowsNull()) {
                return null;
            }

            // Parameter with default value
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw ResolutionException::unresolvedDependency(
                $class,
                $param->getName(),
                $typeName,
                $e->getMessage()
            );
        }
    }

    /**
     * Resolve a complex type (union, intersection).
     * Falls back to default value or null when possible.
     */
    private function resolveComplexType(string $class, \ReflectionParameter $param): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw ResolutionException::unresolvedDependency(
            $class,
            $param->getName(),
            'complex type (union/intersection)'
        );
    }
}

