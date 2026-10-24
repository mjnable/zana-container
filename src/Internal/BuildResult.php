<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\BuildError;

/**
 * Outcome of build validation: the errors found and the plans the container needs.
 *
 * @internal
 */
final readonly class BuildResult
{
    /**
     * @param list<BuildError> $errors
     * @param array<class-string, ConstructorPlan> $plans Implementation class => plan
     * @param array<string, ConstructorPlan> $decoratorPlans "service|decorator class" => plan
     */
    public function __construct(
        public array $errors,
        public array $plans,
        public array $decoratorPlans,
    ) {
    }

    public static function decoratorKey(string $service, string $decorator): string
    {
        return $service . '|' . $decorator;
    }
}
