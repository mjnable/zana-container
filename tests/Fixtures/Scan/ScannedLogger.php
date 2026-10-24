<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Fixtures\Scan;

use Zana\Container\Attribute\Singleton;
use Zana\Container\Tests\Fixtures\LoggerInterface;

#[Singleton(service: LoggerInterface::class, tags: ['loggers'])]
class ScannedLogger implements LoggerInterface
{
    public function log(string $message): string
    {
        return "scanned:{$message}";
    }
}
