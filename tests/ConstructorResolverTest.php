<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceProvider;
use Zana\Container\Resolution\ConstructorResolver;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Tests\Fixtures\ServiceWithDefaults;
use Zana\Container\Tests\Fixtures\ServiceWithDependency;
use Zana\Container\Tests\Fixtures\ServiceWithUnresolvableParam;
use Zana\Container\Tests\Fixtures\DependencyInterface;
use Zana\Container\Tests\Fixtures\DependencyImplementation;
use Zana\Container\Tests\Fixtures\TestService;

class ConstructorResolverTest extends TestCase
{
    private ServiceProvider $provider;
    private ConstructorResolver $resolver;

    protected function setUp(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(DependencyInterface::class, DependencyImplementation::class);
        $this->provider = $collection->build();
        $this->resolver = new ConstructorResolver();
    }

    public function testResolveClassWithNoConstructor(): void
    {
        $instance = $this->resolver->resolve(\stdClass::class, $this->provider);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testResolveClassWithConstructorDependency(): void
    {
        $instance = $this->resolver->resolve(ServiceWithDependency::class, $this->provider);
        $this->assertInstanceOf(ServiceWithDependency::class, $instance);
        $this->assertInstanceOf(DependencyImplementation::class, $instance->dependency);
        $this->assertInstanceOf(TestService::class, $instance->testService);
    }

    public function testResolveClassWithDefaultParameterValues(): void
    {
        $instance = $this->resolver->resolve(ServiceWithDefaults::class, $this->provider);
        $this->assertInstanceOf(ServiceWithDefaults::class, $instance);
        $this->assertEquals('default-name', $instance->name);
        $this->assertEquals(10, $instance->limit);
    }

    public function testResolveThrowsForNonExistentClass(): void
    {
        $this->expectException(ResolutionException::class);
        $this->resolver->resolve('NonExistentClass', $this->provider);
    }

    public function testResolveClassWithUnresolvableDependency(): void
    {
        $this->expectException(ResolutionException::class);
        $this->resolver->resolve(ServiceWithUnresolvableParam::class, $this->provider);
    }
}

