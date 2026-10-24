<?php

declare(strict_types=1);

namespace Zana\Container\Provider;

use Zana\Container\Attribute\Env;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\ResolutionContext;

/**
 * Supplies #[Env(name)] parameters and properties from environment variables.
 *
 * Reads $_ENV, then getenv(), and converts the string to the declared type:
 * string, int, float, bool (true/false/1/0/yes/no/on/off) or a backed enum.
 * A missing variable falls back to the declared default, then to null.
 */
final class EnvValueProvider implements ParameterValueProviderInterface
{
    private const TRUE = ['true', '1', 'yes', 'on'];
    private const FALSE = ['false', '0', 'no', 'off', ''];

    public function supports(\ReflectionParameter|\ReflectionProperty $target): bool
    {
        return $target->getAttributes(Env::class) !== [];
    }

    public function provide(\ReflectionParameter|\ReflectionProperty $target, ResolutionContext $context): mixed
    {
        $name = $target->getAttributes(Env::class)[0]->newInstance()->name;
        $raw = $_ENV[$name] ?? getenv($name);

        if (!is_string($raw)) {
            return $this->fallback($target, $name, $context);
        }

        $type = $target->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return $raw;
        }

        return $this->convert($raw, $type->getName(), $name);
    }

    private function convert(string $raw, string $type, string $name): mixed
    {
        $value = match (true) {
            $type === 'int' => filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            $type === 'float' => filter_var($raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            $type === 'bool' => $this->toBool($raw),
            is_subclass_of($type, \BackedEnum::class) => $this->toEnum($raw, $type),
            default => $raw,
        };

        if ($value === null) {
            throw new ResolutionException("Environment variable {$name}=\"{$raw}\" is not a valid {$type}.");
        }

        return $value;
    }

    private function toBool(string $raw): ?bool
    {
        $normalised = strtolower(trim($raw));

        return match (true) {
            in_array($normalised, self::TRUE, true) => true,
            in_array($normalised, self::FALSE, true) => false,
            default => null,
        };
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private function toEnum(string $raw, string $enum): ?\BackedEnum
    {
        $backing = (string) (new \ReflectionEnum($enum))->getBackingType();
        $value = $backing === 'int' ? filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) : $raw;

        return $value === null ? null : $enum::tryFrom($value);
    }

    private function fallback(\ReflectionParameter|\ReflectionProperty $target, string $name, ResolutionContext $context): mixed
    {
        if ($target instanceof \ReflectionParameter && $target->isDefaultValueAvailable()) {
            return $target->getDefaultValue();
        }

        if ($target instanceof \ReflectionProperty && $target->hasDefaultValue()) {
            return $target->getDefaultValue();
        }

        if ($target->getType()?->allowsNull() ?? true) {
            return null;
        }

        $for = $context->descriptor === null ? '' : " (needed by {$context->descriptor->service})";

        throw new ResolutionException("Environment variable {$name} is not set{$for}.");
    }
}
