<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

/**
 * Marks an attribute whose value comes from a parameter value provider, such as
 * #[Env] or zana/configuration's #[Config].
 *
 * A parameter or property carrying a value attribute that no registered
 * provider supports fails the build (B11). Properties carrying one are
 * injected after construction.
 */
interface ValueAttribute
{
}
