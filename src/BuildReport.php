<?php

declare(strict_types=1);

namespace Zana\Container;

use Zana\Container\Graph\ServiceGraph;

/**
 * Result of ServiceCollection::analyze(): the validation errors and the
 * dependency graph, available even when the collection is invalid.
 */
final readonly class BuildReport
{
    /**
     * @param list<BuildError> $errors
     */
    public function __construct(
        private ServiceGraph $graph,
        private array $errors,
    ) {
    }

    public function graph(): ServiceGraph
    {
        return $this->graph;
    }

    /**
     * @return list<BuildError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
