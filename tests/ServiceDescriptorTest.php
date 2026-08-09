<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Descriptors\ServiceDescriptor;
use Zana\Contracts\Container\ServiceLifetime;

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

    public function testValidSelfRegistrationStrategy(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
        );

        $this->assertTrue($descriptor->isSelfRegistered());
        $this->assertFalse($descriptor->isFactory());
        $this->assertFalse($descriptor->isInstance());
        $this->assertFalse($descriptor->hasImplementation());
    }

    public function testValidImplementationStrategy(): void
    {
        $descriptor = new ServiceDescriptor(
            service: 'service',
            implementation: \stdClass::class,
        );

        $this->assertTrue($descriptor->hasImplementation());
        $this->assertFalse($descriptor->isFactory());
        $this->assertFalse($descriptor->isInstance());
        $this->assertFalse($descriptor->isSelfRegistered());
    }

    public function testValidFactoryStrategy(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            factory: fn () => new \stdClass(),
        );

        $this->assertTrue($descriptor->isFactory());
        $this->assertFalse($descriptor->isInstance());
        $this->assertFalse($descriptor->hasImplementation());
        $this->assertFalse($descriptor->isSelfRegistered());
    }

    public function testValidInstanceStrategy(): void
    {
        $descriptor = new ServiceDescriptor(
            service: \stdClass::class,
            instance: new \stdClass(),
        );

        $this->assertTrue($descriptor->isInstance());
        $this->assertFalse($descriptor->isFactory());
        $this->assertFalse($descriptor->hasImplementation());
        $this->assertFalse($descriptor->isSelfRegistered());
    }

    public function testInvalidImplementationAndFactoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one creation strategy is active at a time');

        new ServiceDescriptor(
            service: \stdClass::class,
            implementation: \stdClass::class,
            factory: fn () => new \stdClass(),
        );
    }

    public function testInvalidImplementationAndInstanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one creation strategy is active at a time');

        new ServiceDescriptor(
            service: \stdClass::class,
            implementation: \stdClass::class,
            instance: new \stdClass(),
        );
    }

    public function testInvalidFactoryAndInstanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one creation strategy is active at a time');

        new ServiceDescriptor(
            service: \stdClass::class,
            factory: fn () => new \stdClass(),
            instance: new \stdClass(),
        );
    }

    public function testInvalidAllThreeStrategiesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one creation strategy is active at a time');

        new ServiceDescriptor(
            service: \stdClass::class,
            implementation: \stdClass::class,
            factory: fn () => new \stdClass(),
            instance: new \stdClass(),
        );
    }
}
