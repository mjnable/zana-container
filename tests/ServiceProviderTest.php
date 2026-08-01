<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceProvider;
use Zana\Container\Exception\NotFoundException;
use Zana\Container\Exception\CircularDependencyException;
use Zana\Container\Tests\Fixtures\TestService;

class ServiceProviderTest extends TestCase
{
    private ServiceProvider $provider;

    protected function setUp(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        $collection->transient('transient-service', \stdClass::class);
        $collection->singleton('named-service', \stdClass::class);
        $collection->alias('alias', 'named-service');
        $this->provider = $collection->build();
    }

    public function testGetRegisteredService(): void
    {
        $instance = $this->provider->get(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testGetThrowsNotFoundExceptionForMissingService(): void
    {
        $this->expectException(NotFoundException::class);
        $this->provider->get('non-existent-service');
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $this->assertTrue($this->provider->has(\stdClass::class));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $this->assertFalse($this->provider->has('non-existent-service'));
    }

    public function testHasReturnsTrueForAutoWireableClass(): void
    {
        $this->assertTrue($this->provider->has(self::class));
    }

    public function testSingletonCaching(): void
    {
        $instance1 = $this->provider->get(\stdClass::class);
        $instance2 = $this->provider->get(\stdClass::class);

        $this->assertSame($instance1, $instance2);
    }

    public function testTransientCreatesNewInstance(): void
    {
        $instance1 = $this->provider->get('transient-service');
        $instance2 = $this->provider->get('transient-service');

        $this->assertNotSame($instance1, $instance2);
    }

    public function testTryGetReturnsInstanceForRegisteredService(): void
    {
        $instance = $this->provider->tryGet(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testTryGetReturnsNullForMissingService(): void
    {
        $result = $this->provider->tryGet('non-existent-service');
        $this->assertNull($result);
    }

    public function testGetAllReturnsMultipleImplementations(): void
    {
        $collection = new ServiceCollection();
        $collection->transient('multi-service', \stdClass::class);
        $collection->transient('multi-service', \stdClass::class);
        $provider = $collection->build();

        $results = $provider->getAll('multi-service');
        $this->assertCount(2, $results);
        $this->assertNotSame($results[0], $results[1]);
    }

    public function testAliasResolution(): void
    {
        $instance = $this->provider->get('alias');
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testCircularDependencyDetection(): void
    {
        $this->expectException(CircularDependencyException::class);

        $collection = new ServiceCollection();
        $collection->singleton('class-a', function ($c) { return $c->get('class-b'); });
        $collection->singleton('class-b', function ($c) { return $c->get('class-a'); });
        $provider = $collection->build();

        $provider->get('class-a');
    }

    public function testAutoWireConcreteClass(): void
    {
        $instance = $this->provider->get(TestService::class);
        $this->assertInstanceOf(TestService::class, $instance);
    }

    public function testParentProviderDelegation(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        $root = $collection->build();

        $childCollection = new ServiceCollection();
        $childProvider = $childCollection->build();
        $childProvider->setParent($root);

        $instance = $childProvider->get(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }
}

