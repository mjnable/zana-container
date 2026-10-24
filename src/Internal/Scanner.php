<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\BuildError;
use Zana\Container\Exception\ContainerException;
use Zana\Container\ServiceDescriptor;

/**
 * Finds attributed classes in a PSR-4 directory (specification §5.11).
 *
 * Only classes carrying a lifetime attribute are registered; scanning never
 * registers a class implicitly.
 *
 * @internal
 */
final class Scanner
{
    /**
     * @param list<string> $activeProfiles
     * @return array{list<ServiceDescriptor>, array<string, list<string>>, list<BuildError>}
     *         Registrations sorted by class name, their tags, and B11/B13 errors
     */
    public static function scan(string $directory, string $namespace, ?string $origin, array $activeProfiles): array
    {
        if (!is_dir($directory)) {
            return [[], [], [new BuildError('B13', "scan directory {$directory} does not exist", origin: $origin)]];
        }

        $namespace = trim($namespace, '\\');
        $root = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/');
        $classes = [];
        $errors = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1, -4);
            $class = ($namespace === '' ? '' : $namespace . '\\') . str_replace('/', '\\', $relative);

            // Autoload at most once per file: if it declares another class, a
            // second include would redeclare that class. The first check loads
            // the file whatever it declares, so the others only look.
            $autoload = !in_array((string) realpath($file->getPathname()), get_included_files(), true);

            try {
                $loaded = class_exists($class, $autoload) || interface_exists($class, false)
                    || trait_exists($class, false) || enum_exists($class, false);
            } catch (\Throwable $e) {
                $errors[] = new BuildError('B13', "scanned file {$file->getPathname()} could not be loaded: {$e->getMessage()}", $class, origin: $origin);
                continue;
            }

            if (!$loaded) {
                $errors[] = new BuildError('B13', "scanned file {$file->getPathname()} does not declare the autoloadable class {$class}", $class, origin: $origin);
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        $descriptors = [];
        $tags = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isEnum() || !AttributeReader::hasLifetimeAttribute($reflection)) {
                continue;
            }

            try {
                $registration = AttributeReader::registration($reflection, $activeProfiles, $origin);
            } catch (ContainerException $e) {
                $errors[] = new BuildError('B11', $e->getMessage(), $class, origin: $origin);
                continue;
            }

            if ($registration === null) {
                continue;
            }

            [$descriptor, $classTags] = $registration;
            $descriptors[] = $descriptor;
            foreach ($classTags as $tag) {
                $tags[$tag][] = $descriptor->service;
            }
        }

        return [$descriptors, $tags, $errors];
    }
}
