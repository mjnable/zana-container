<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceProvider;
use Zana\Container\Scopes\ServiceScope;
use Zana\Contracts\Container\ServiceScopeInterface;
use Zana\Contracts\Container\ScopeType;
use Zana\Contracts\Container\DisposableInterface;
use Zana\Container\Scopes\ScopeMetadata;

class ServiceScopeTest extends TestCase
{
    private ServiceProvider $provider;

    protected function setUp(): void
    {
        $collection = new ServiceCollection();
        $collection->scoped(\stdClass::class);
        $this->provider = $collection->build();
    }

    public function testCreateScopeReturnsServiceScope(): void
    {
        $scope = $this->provider->createScope();
        $this->assertInstanceOf(ServiceScope::class, $scope);
        $this->assertInstanceOf(ServiceScopeInterface::class, $scope);
    }

    public function testScopeProvidesAccessToProvider(): void
    {
        $scope = $this->provider->createScope();
        $this->assertSame($this->provider, $scope->getProvider());
    }

    public function testScopeResolvesScopedService(): void
    {
        $scope = $this->provider->createScope();
        $instance = $scope->get(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function testScopedServiceSharedWithinScope(): void
    {
        $scope = $this->provider->createScope();
        $instance1 = $scope->get(\stdClass::class);
        $instance2 = $scope->get(\stdClass::class);

        $this->assertSame($instance1, $instance2);
    }

    public function testScopedServiceDifferentAcrossScopes(): void
    {
        $scope1 = $this->provider->createScope();
        $scope2 = $this->provider->createScope();

        $instance1 = $scope1->get(\stdClass::class);
        $instance2 = $scope2->get(\stdClass::class);

        $this->assertNotSame($instance1, $instance2);
    }

    public function testDisposeMarksScopeDisposed(): void
    {
        $scope = $this->provider->createScope();
        $this->assertFalse($scope->isDisposed());

        $scope->dispose();
        $this->assertTrue($scope->isDisposed());
    }

    public function testScopeWithMetadata(): void
    {
        $metadata = ScopeMetadata::http();
        $scope = $this->provider->createScope($metadata);
        $this->assertSame($metadata, $scope->getMetadata());
        $this->assertNotNull($scope->getMetadata()?->id());
        $this->assertEquals(ScopeType::Http, $scope->getMetadata()?->type());
    }

    public function testScopeDisposesDisposableServices(): void
    {
        $disposableService = new class() implements DisposableInterface {
            public bool $disposed = false;
            public function dispose(): void
            {
                $this->disposed = true;
            }
        };

        $collection = new ServiceCollection();
        $collection->scoped('disposable', get_class($disposableService));
        $provider = $collection->build();

        $scope = $provider->createScope();
        $instance = $scope->get('disposable');
        $this->assertFalse($instance->disposed);

        $scope->dispose();
        $this->assertTrue($instance->disposed);
    }

    public function testChildScopeInheritsParentProvider(): void
    {
        $scope = $this->provider->createScope();
        $child = $scope->createChild();

        $this->assertSame($this->provider, $child->getProvider());
        $this->assertSame($scope, $child->getParent());
    }

    public function testDisposeAfterDisposeIsIdempotent(): void
    {
        $scope = $this->provider->createScope();
        $scope->dispose();
        $scope->dispose();

        $this->assertTrue($scope->isDisposed());
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $scope = $this->provider->createScope();
        $this->assertTrue($scope->has(\stdClass::class));
    }

    public function testHasReturnsFalseForMissingService(): void
    {
        $scope = $this->provider->createScope();
        $this->assertFalse($scope->has('non-existent-service'));
    }
}
