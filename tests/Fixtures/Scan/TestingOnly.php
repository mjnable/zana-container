<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures\Scan;

use Zana\Container\Attribute\Profile;
use Zana\Container\Attribute\Transient;

#[Transient]
#[Profile('testing')]
class TestingOnly
{
}
