<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;
use Zana\Container\ServiceLifetime;

/**
 * Register the class as a scoped service through addAttributed() or scan().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Scoped extends RegistrationAttribute
{
    public function lifetime(): ServiceLifetime
    {
        return ServiceLifetime::Scoped;
    }
}
