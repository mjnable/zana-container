<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures\ScanBroken;

use Zana\Container\Attribute\Scoped;
use Zana\Container\Attribute\Transient;

#[Scoped]
#[Transient]
class DoubleLifetime
{
}
