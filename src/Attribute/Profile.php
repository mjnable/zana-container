<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;

/**
 * Register an attributed class only when at least one of the profiles is active.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Profile
{
    /** @var list<string> */
    public readonly array $profiles;

    public function __construct(string ...$profiles)
    {
        $this->profiles = array_values($profiles);
    }
}
