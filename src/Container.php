<?php

declare(strict_types=1);

namespace Zana\Container;

use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Internal\AbstractResolver;
use Zana\Container\Internal\Engine;
use Zana\Container\Internal\Registry;

/**
 * The root container produced by ServiceCollection::build(). Owns singletons.
 *
 * @internal Type-hint Contracts\ContainerInterface instead.
 */
final class Container extends AbstractResolver implements ContainerInterface
{
    public function __construct(Registry $registry, Engine $engine)
    {
        parent::__construct($registry, $engine, ScopeMetadata::root()->completed(null));
    }

    public function root(): Container
    {
        return $this;
    }

    public function isScope(): bool
    {
        return false;
    }
}
