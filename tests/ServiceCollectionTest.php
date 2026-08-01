<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceProvider;
use Zana\Container\Exception\ContainerException;

class ServiceCollectionTest extends TestCase
{
    public function testSingletonRegistration(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        
        $provider = $collection->build();
        $instance1 = $provider->get(\stdClass::class);
        $instance2 = $provider->get(\stdClass::class);
        
        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testScopedRegistration(): void
    {
        $collection = new ServiceCollection();
        $collection->scoped(\stdClass::class);
        
        $provider = $collection->build();
        $instance1 = $provider->get(\stdClass::class);
        $instance2 = $provider->get(\stdClass::class);
        
        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testTransientRegistration(): void
    {
        $collection = new ServiceCollection();
        $collection->transient(\stdClass::class);
        
        $provider = $collection->build();
        $instance1 = $provider->get(\stdClass::class);
        $instance2 = $provider->get(\stdClass::class);
        
        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    public function testInstanceRegistration(): void
    {
        $collection = new ServiceCollection();
        $instance = new \stdClass();
        $instance->foo = 'bar';
        
        $collection->instance(\stdClass::class, $instance);
        $provider = $collection->build();
        $resolved = $provider->get(\stdClass::class);
        
        $this->assertSame($instance, $resolved);
        $this->assertEquals('bar', $resolved->foo);
    }

    public function testAlias(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        $collection->alias('my-alias', \stdClass::class);
        
        $provider = $collection->build();
        $instance = $provider->get('my-alias');
        
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testRemove(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        $this->assertTrue($collection->has(\stdClass::class));
        
        $collection->remove(\stdClass::class);
        $this->assertFalse($collection->has(\stdClass::class));
    }

    public function testHas(): void
    {
        $collection = new ServiceCollection();
        $this->assertFalse($collection->has(\stdClass::class));
        
        $collection->singleton(\stdClass::class);
        $this->assertTrue($collection->has(\stdClass::class));
    }

    public function testBuildReturnsServiceProvider(): void
    {
        $collection = new ServiceCollection();
        $provider = $collection->build();
        
        $this->assertInstanceOf(ServiceProvider::class, $provider);
    }

    public function testBuildThrowsExceptionWhenAlreadyBuilt(): void
    {
        $this->expectException(ContainerException::class);
        
        $collection = new ServiceCollection();
        $collection->build();
        $collection->singleton(\stdClass::class);
    }

    public function testFactoryRegistration(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class, function () {
            $obj = new \stdClass();
            $obj->factory = true;
            return $obj;
        });
        
        $provider = $collection->build();
        $instance = $provider->get(\stdClass::class);
        
        $this->assertTrue($instance->factory);
    }
}

