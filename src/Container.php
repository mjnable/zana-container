<?php

declare(strict_types=1);

namespace Zana\Container;

use Closure;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Graph\ServiceGraph;
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
    private ?ServiceGraph $graph = null;

    /**
     * @param Closure(): ServiceGraph $graphFactory Builds the graph on first request
     */
    public function __construct(Registry $registry, Engine $engine, private readonly Closure $graphFactory)
    {
        parent::__construct($registry, $engine, ScopeMetadata::root()->completed(null));
    }

    public function graph(): ServiceGraph
    {
        return $this->graph ??= ($this->graphFactory)();
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
