<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

/**
 * The root container produced by ServiceCollection::build().
 */
interface ContainerInterface extends ServiceResolverInterface
{
}
