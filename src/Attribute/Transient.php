<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;
use Zana\Container\ServiceLifetime;

/**
 * Register the class as a transient service through addAttributed() or scan().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Transient extends RegistrationAttribute
{
    public function lifetime(): ServiceLifetime
    {
        return ServiceLifetime::Transient;
    }
}
