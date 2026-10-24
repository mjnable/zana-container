<?php

declare(strict_types=1);

namespace Zana\Container;

/**
 * One validation failure reported by ServiceCollection::build().
 */
final readonly class BuildError
{
    /**
     * @param string $code Rule identifier, B1 to B8 (see the container specification §10)
     * @param string $message What is wrong and, where possible, how to fix it
     * @param string|null $service The service the error is about, if any
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?string $service = null,
        public ?string $key = null,
        public ?string $origin = null,
    ) {
    }

    public function __toString(): string
    {
        return "[{$this->code}] {$this->message}";
    }
}
