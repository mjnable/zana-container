<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;

/**
 * Inject every member of a tag. The parameter or property must be typed array or iterable.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Tagged
{
    public function __construct(public readonly string $tag)
    {
    }
}
