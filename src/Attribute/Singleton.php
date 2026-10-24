<?php

declare(strict_types=1);

namespace Zana\Container\Attribute;

use Attribute;
use Zana\Container\ServiceLifetime;

/**
 * Register the class as a singleton through addAttributed() or scan().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton extends RegistrationAttribute
{
    public function lifetime(): ServiceLifetime
    {
        return ServiceLifetime::Singleton;
    }
}
