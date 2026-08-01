<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\ServiceCollection;
use Zana\Container\ServiceProvider;
use Zana\Container\Resolution\ParameterResolver;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Tests\Fixtures\DependencyInterface;

class ParameterResolverTest extends TestCase
{
    private ServiceProvider $provider;
    private ParameterResolver $resolver;

    protected function setUp(): void
    {
        $collection = new ServiceCollection();
        $collection->singleton(\stdClass::class);
        $this->provider = $collection->build();
        $this->resolver = new ParameterResolver();
    }

    public function testResolveClassTypeFromContainer(): void
    {
        $param = $this->getConstructorParam(ClassDependency::class, 'dependency');
        $result = $this->resolver->resolve(ClassDependency::class, $param, $this->provider);
        $this->assertInstanceOf(\stdClass::class, $result);
    }

    public function testResolveBuiltinTypeWithDefaultValue(): void
    {
        $param = $this->getConstructorParam(DefaultValueDependency::class, 'name');
        $result = $this->resolver->resolve(DefaultValueDependency::class, $param, $this->provider);
        $this->assertEquals('default', $result);
    }

    public function testResolveNullableClassTypeReturnsNullWhenNotRegistered(): void
    {
        $param = $this->getConstructorParam(NullableInterfaceDependency::class, 'dependency');
        $result = $this->resolver->resolve(NullableInterfaceDependency::class, $param, $this->provider);
        $this->assertNull($result);
    }

    public function testResolveThrowsForUnresolvableBuiltinType(): void
    {
        $this->expectException(ResolutionException::class);

        $param = $this->getConstructorParam(RequiredBuiltinDependency::class, 'value');
        $this->resolver->resolve(RequiredBuiltinDependency::class, $param, $this->provider);
    }

    public function testResolveUntypedParamWithDefaultValue(): void
    {
        $param = $this->getConstructorParam(UntypedDefaultDependency::class, 'value');
        $result = $this->resolver->resolve(UntypedDefaultDependency::class, $param, $this->provider);
        $this->assertEquals(42, $result);
    }

    private function getConstructorParam(string $class, string $paramName): \ReflectionParameter
    {
        $constructor = new \ReflectionMethod($class, '__construct');
        foreach ($constructor->getParameters() as $param) {
            if ($param->getName() === $paramName) {
                return $param;
            }
        }
        throw new \RuntimeException("Parameter {$paramName} not found in {$class}");
    }
}

class ClassDependency
{
    public function __construct(public \stdClass $dependency)
    {
    }
}

class DefaultValueDependency
{
    public function __construct(public string $name = 'default')
    {
    }
}

class NullableInterfaceDependency
{
    public function __construct(public ?DependencyInterface $dependency = null)
    {
    }
}

class RequiredBuiltinDependency
{
    public function __construct(public int $value)
    {
    }
}

class UntypedDefaultDependency
{
    public function __construct(public $value = 42)
    {
    }
}

