<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;

/**
 * Inject the value of an environment variable. Needs EnvValueProvider to be registered.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Env implements ValueAttribute
{
    public function __construct(public readonly string $name)
    {
    }
}
