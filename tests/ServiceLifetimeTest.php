<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Contracts\Container\ServiceLifetime;

class ServiceLifetimeTest extends TestCase
{
    public function testSingletonCaseExists(): void
    {
        $this->assertTrue(defined(ServiceLifetime::class . '::Singleton'));
    }

    public function testScopedCaseExists(): void
    {
        $this->assertTrue(defined(ServiceLifetime::class . '::Scoped'));
    }

    public function testTransientCaseExists(): void
    {
        $this->assertTrue(defined(ServiceLifetime::class . '::Transient'));
    }

    public function testCasesAreUnique(): void
    {
        $cases = ServiceLifetime::cases();
        $names = array_map(fn($case) => $case->name, $cases);

        $this->assertCount(3, $cases);
        $this->assertCount(3, array_unique($names));
        $this->assertContains('Singleton', $names);
        $this->assertContains('Scoped', $names);
        $this->assertContains('Transient', $names);
    }
}
