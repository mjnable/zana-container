<?php

declare(strict_types=1);

namespace Zana\Container\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Base exception for all container-related errors.
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}

