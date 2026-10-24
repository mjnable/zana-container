<?php

declare(strict_types=1);

namespace Zana\Container\Tests\Support;

use Zana\Container\ContainerCompiler;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ServiceCollection;

/**
 * The collection every test uses. With ZANA_CONTAINER_COMPILED=1, build()
 * compiles the collection and builds from the generated class instead, so the
 * whole suite also checks that compiled containers behave identically
 * (container specification §11, "equivalence").
 */
final class TestServiceCollection extends ServiceCollection
{
    private static int $compiled = 0;

    public static function compiledMode(): bool
    {
        return getenv('ZANA_CONTAINER_COMPILED') === '1';
    }

    public function build(?string $compiled = null): ContainerInterface
    {
        if ($compiled !== null || !self::compiledMode()) {
            return parent::build($compiled);
        }

        $class = 'ZanaCompiledTests\\Container' . ++self::$compiled;
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zana-compiled-' . getmypid() . '-' . self::$compiled . '.php';

        file_put_contents($file, (new ContainerCompiler())->compile($this, $class));
        try {
            require $file;
        } finally {
            unlink($file);
        }

        if (!class_exists($class, false)) {
            throw new \LogicException("The compiled container {$class} was not declared.");
        }

        return parent::build($class);
    }
}
