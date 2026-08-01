<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceDescriptor;
use Zana\Container\ServiceLifetime;

class ServiceDescriptorTest extends TestCase
{
    public function testConstructor(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            implementation: \stdClass::class,
            lifetime: ServiceLifetime::Singleton,
            metadata: ['tag' => 'test']
        );

        $this->assertEquals(\stdClass::class, $descriptor->service);
        $this->assertEquals(\stdClass::class, $descriptor->implementation);
        $this->assertEquals(ServiceLifetime::Singleton, $descriptor->lifetime);
        $this->assertNull($descriptor->factory);
        $this->assertNull($descriptor->instance);
        $this->assertEquals(['tag' => 'test'], $descriptor->metadata);
    }

    public function testIsSelfRegistered(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
        );

        $this->assertTrue($descriptor->isSelfRegistered());
    }

    public function testIsNotSelfRegisteredWhenImplementationSet(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            implementation: \stdClass::class,
        );

        $this->assertFalse($descriptor->isSelfRegistered());
    }

    public function testIsFactory(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            factory: function () {
                return new \stdClass();
            },
        );

        $this->assertTrue($descriptor->isFactory());
        $this->assertFalse($descriptor->isInstance());
    }

    public function testIsInstance(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            instance: new \stdClass(),
        );

        $this->assertTrue($descriptor->isInstance());
        $this->assertFalse($descriptor->isFactory());
    }

    public function testHasImplementation(): void
    {
        $descriptor = new ServiceDescriptor(
            service: 'service',
            implementation: \stdClass::class,
        );

        $this->assertTrue($descriptor->hasImplementation());
    }

    public function testGetConcreteTypeWithImplementation(): void
    {
        $descriptor = new ServiceDescriptor(
            service: 'service',
            implementation: \stdClass::class,
        );

        $this->assertEquals(\stdClass::class, $descriptor->getConcreteType());
    }

    public function testGetConcreteTypeWithSelfRegistration(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
        );

        $this->assertEquals(\stdClass::class, $descriptor->getConcreteType());
    }

    public function testDefaultLifetimeIsTransient(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
        );

        $this->assertEquals(ServiceLifetime::Transient, $descriptor->lifetime);
    }
}

