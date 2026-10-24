<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;

/**
 * On a parameter: resolve this service id (and key) instead of the declared type.
 * On a property or method: inject it after construction.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Inject
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $key = null,
    ) {
    }
}
