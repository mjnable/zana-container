<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\Attribute\Profile;
use Zana\Container\Attribute\RegistrationAttribute;
use Zana\Container\Exception\ContainerException;
use Zana\Container\ServiceDescriptor;

/**
 * Turns a class's lifetime attribute into a registration (specification §5.10).
 *
 * @internal
 */
final class AttributeReader
{
    /**
     * @param \ReflectionClass<object> $class
     * @param list<string> $activeProfiles
     * @return array{ServiceDescriptor, list<string>}|null The registration and its tags,
     *                                                     or null when its #[Profile] is inactive
     * @throws ContainerException If the class has no lifetime attribute or more than one
     */
    public static function registration(\ReflectionClass $class, array $activeProfiles, ?string $origin): ?array
    {
        $attributes = $class->getAttributes(RegistrationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new ContainerException(
                "{$class->getName()} has no #[Singleton], #[Scoped] or #[Transient] attribute."
            );
        }

        if (count($attributes) > 1) {
            throw new ContainerException(
                "{$class->getName()} has more than one lifetime attribute; keep exactly one."
            );
        }

        foreach ($class->getAttributes(Profile::class) as $profile) {
            if (array_intersect($profile->newInstance()->profiles, $activeProfiles) === []) {
                return null;
            }
        }

        $attribute = $attributes[0]->newInstance();

        return [
            new ServiceDescriptor(
                service: $attribute->service ?? $class->getName(),
                lifetime: $attribute->lifetime(),
                implementation: $class->getName(),
                key: $attribute->key,
                origin: $origin,
            ),
            $attribute->key === null ? $attribute->tags : [],
        ];
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    public static function hasLifetimeAttribute(\ReflectionClass $class): bool
    {
        return $class->getAttributes(RegistrationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
    }
}
