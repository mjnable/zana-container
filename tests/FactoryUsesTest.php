<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\BuildError;
use Zana\Container\ContainerCompiler;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\Exception\ScopeException;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\Helper;
use Zana\Container\Tests\Fixtures\UnitOfWork;
use Zana\Container\Tests\Support\TestServiceCollection;

/**
 * FE-40: factories declare what they resolve, so the build checks it; and a
 * singleton that resolves a scoped service at run time is named.
 */
final class FactoryUsesTest extends TestCase
{
    public function testASingletonFactoryUsingAScopedServiceFailsTheBuild(): void
    {
        $services = (new TestServiceCollection())
            ->scoped(UnitOfWork::class)
            ->singleton('reset.tokens', static fn ($r): object => $r->get(UnitOfWork::class), origin: 'package:security')
            ->uses(UnitOfWork::class);

        $this->assertError($services, 'B5', 'reset.tokens (singleton, package:security) → ' . UnitOfWork::class . ' (scoped): a singleton must not depend on a scoped service');
    }

    public function testTheCheckFollowsTransientsAndClasses(): void
    {
        $services = (new TestServiceCollection())
            ->scoped(UnitOfWork::class)
            ->transient(Helper::class)                       // constructor: UnitOfWork
            ->transient('report', static fn ($r): object => $r->get(Helper::class))->uses(Helper::class)
            ->singleton('cache', static fn ($r): object => $r->get('report'))->uses('report');

        $this->assertError($services, 'B5', 'cache (singleton) → report (transient) → ' . Helper::class . ' (transient) → ' . UnitOfWork::class . ' (scoped)');
    }

    public function testUnregisteredDeclaredServicesAreAllowed(): void
    {
        // A package factory for a feature the application does not use
        $container = (new TestServiceCollection())->singleton('mailer', static fn ($r): object => $r->get('transport'))->uses('transport')->build();

        $this->assertTrue($container->has('mailer'));
    }

    public function testCyclesThroughFactoriesAreFound(): void
    {
        $services = (new TestServiceCollection())
            ->singleton('a', static fn ($r): object => $r->get('b'))->uses('b')
            ->singleton('b', static fn ($r): object => $r->get('a'))->uses('a');

        $this->assertError($services, 'B4', 'circular dependency: a → b → a');
    }

    public function testValidDeclarationsBuildAndShowInTheGraph(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Clock::class)
            ->scoped(UnitOfWork::class)
            ->scoped('report', static fn ($r): object => $r->get(UnitOfWork::class))->uses(UnitOfWork::class, Clock::class)
            ->build();

        $graph = $container->graph();
        $factoryEdges = array_values(array_filter($graph->edges(), static fn ($edge): bool => $edge->kind === 'factory'));
        $this->assertCount(2, $factoryEdges);
        $this->assertSame('report', $graph->nodes()[$factoryEdges[0]->from]->service);
    }

    public function testUsesFollowsTheRegistrationJustMade(): void
    {
        $services = (new ServiceCollection())
            ->scoped('clock', static fn (): object => new Clock())->uses(UnitOfWork::class)
            ->scoped(UnitOfWork::class)
            ->trySingleton('clock', static fn (): object => new Clock())->uses('ignored');   // registered nothing: no effect

        $this->assertSame([UnitOfWork::class], $services->descriptors()[0]->uses);
        $services->build();
    }

    public function testUsesNeedsAFactoryRegistration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Clock::class . ' is not registered with a factory, and its constructor is checked already');

        (new ServiceCollection())->singleton(Clock::class)->uses(UnitOfWork::class);
    }

    public function testUsesNeedsARegistration(): void
    {
        $this->expectException(\LogicException::class);

        (new ServiceCollection())->uses(UnitOfWork::class);
    }

    public function testAnUndeclaredCaptiveIsNamedAtRunTime(): void
    {
        $container = (new TestServiceCollection())
            ->scoped(UnitOfWork::class, origin: 'module:Editors')
            ->singleton('reset.tokens', static fn ($r): object => $r->get(UnitOfWork::class), origin: 'package:security')
            ->build();

        $scope = $container->createScope();
        try {
            $scope->get('reset.tokens');
            $this->fail('Expected a scope error.');
        } catch (ScopeException $e) {
            $this->assertStringContainsString('Singleton reset.tokens (singleton, package:security) resolves scoped service ' . UnitOfWork::class . ' (scoped, module:Editors) in its factory', $e->getMessage());
            $this->assertStringContainsString('Make the second a singleton, or the first scoped.', $e->getMessage());
            $this->assertStringContainsString('->uses()', $e->getMessage());
        } finally {
            $scope->dispose();
        }
    }

    public function testAScopedServiceFromTheRootKeepsItsMessage(): void
    {
        $container = (new TestServiceCollection())->scoped(UnitOfWork::class)->build();

        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage('Scoped service ' . UnitOfWork::class . ' cannot be resolved from the root container.');
        $container->get(UnitOfWork::class);
    }

    public function testDeclarationsChangeTheCompiledFingerprintOnlyWhenPresent(): void
    {
        $plain = static fn (): ServiceCollection => (new ServiceCollection())->singleton('clock', static fn (): object => new Clock());
        $declared = static fn (): ServiceCollection => (new ServiceCollection())->singleton(Clock::class)->singleton('clock', static fn ($r): object => $r->get(Clock::class))->uses(Clock::class);

        $compiler = new ContainerCompiler();
        $this->assertSame(
            $this->fingerprint($compiler->compile($plain(), 'A')),
            $this->fingerprint($compiler->compile((new ServiceCollection())->singleton('clock', static fn (): object => new Clock()), 'B')),
        );
        $this->assertNotSame(
            $this->fingerprint($compiler->compile($declared(), 'C')),
            $this->fingerprint($compiler->compile((new ServiceCollection())->singleton(Clock::class)->singleton('clock', static fn ($r): object => $r->get(Clock::class)), 'D')),
        );
    }

    private function fingerprint(string $source): string
    {
        preg_match("/FINGERPRINT = '([0-9a-f]+)'/", $source, $match);

        return $match[1] ?? '';
    }

    private function assertError(ServiceCollection $services, string $code, string $message): void
    {
        try {
            $services->build();
        } catch (ContainerBuildException $e) {
            $matching = array_filter($e->errors(), static fn (BuildError $error): bool => $error->code === $code && str_contains($error->message, $message));
            $this->assertNotEmpty($matching, "Expected [{$code}] containing \"{$message}\", got:\n" . implode("\n", array_map('strval', $e->errors())));

            return;
        }

        $this->fail('The build should fail.');
    }
}
