<?php

declare(strict_types=1);

namespace Zana\Container\Contracts;

use Zana\Container\Graph\ServiceGraph;

/**
 * The root container produced by ServiceCollection::build().
 */
interface ContainerInterface extends ServiceResolverInterface
{
    /**
     * The dependency graph of the registrations, built without creating services.
     */
    public function graph(): ServiceGraph;
}
