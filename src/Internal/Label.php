<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

/**
 * Human-readable names for registrations in error messages.
 *
 * @internal
 */
final class Label
{
    /**
     * "PatientService [key: main] (module:Patients)"
     */
    public static function of(ServiceDescriptor $descriptor): string
    {
        $label = $descriptor->service;

        if ($descriptor->key !== null) {
            $label .= " [key: {$descriptor->key}]";
        }

        if ($descriptor->origin !== null) {
            $label .= " ({$descriptor->origin})";
        }

        return $label;
    }

    /**
     * "ReportCache (singleton, package:zana/reporting)"
     */
    public static function withLifetime(ServiceDescriptor $descriptor): string
    {
        $details = array_filter([self::lifetime($descriptor->lifetime), $descriptor->origin]);
        $label = $descriptor->service . ($descriptor->key !== null ? " [key: {$descriptor->key}]" : '');

        return $label . ' (' . implode(', ', $details) . ')';
    }

    public static function lifetime(ServiceLifetime $lifetime): string
    {
        return strtolower($lifetime->name);
    }
}
