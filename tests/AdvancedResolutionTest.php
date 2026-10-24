<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use Zana\Container\Tests\Support\TestServiceCollection;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Zana\Container\Exception\ResolutionException;
use Zana\Container\Provider\EnvValueProvider;
use Zana\Container\ServiceCollection;
use Zana\Container\Tests\Fixtures\ArraySettingsProvider;
use Zana\Container\Tests\Fixtures\AuditLogger;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\Color;
use Zana\Container\Tests\Fixtures\EmailNotifier;
use Zana\Container\Tests\Fixtures\EnvLevel;
use Zana\Container\Tests\Fixtures\EnvRequired;
use Zana\Container\Tests\Fixtures\EnvSettings;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\Level;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\NeedsDnf;
use Zana\Container\Tests\Fixtures\NeedsOptionalUnion;
use Zana\Container\Tests\Fixtures\NeedsStream;
use Zana\Container\Tests\Fixtures\NeedsUnion;
use Zana\Container\Tests\Fixtures\NullLogger;
use Zana\Container\Tests\Fixtures\PushNotifier;
use Zana\Container\Tests\Fixtures\Readable;
use Zana\Container\Tests\Fixtures\ReadOnlyStream;
use Zana\Container\Tests\Fixtures\Stream;
use Zana\Container\Tests\Fixtures\UsesInjectedParameters;
use Zana\Container\Tests\Fixtures\UsesSettings;
use Zana\Container\Tests\Fixtures\Writable;

final class AdvancedResolutionTest extends TestCase
{
    private const ENV = ['ZANA_TEST_PORT', 'ZANA_TEST_DEBUG', 'ZANA_TEST_COLOR', 'ZANA_TEST_RATIO', 'ZANA_TEST_NAME', 'ZANA_TEST_LEVEL', 'ZANA_TEST_UNSET'];

    #[After]
    public function clearEnvironment(): void
    {
        foreach (self::ENV as $name) {
            unset($_ENV[$name]);
            putenv($name);
        }
    }

    // ── §6.2 steps 1 and 2: #[Inject] and #[Tagged] ────────────────────────

    public function testInjectAndTaggedParameters(): void
    {
        $container = (new TestServiceCollection())
            ->addAttributed(AuditLogger::class)
            ->singleton('primary-logger', FileLogger::class)
            ->singleton(PushNotifier::class)
            ->singleton(EmailNotifier::class)
            ->tag('notifiers', PushNotifier::class, EmailNotifier::class)
            ->transient(UsesInjectedParameters::class)
            ->build();

        $service = $container->createScope()->get(UsesInjectedParameters::class);

        $this->assertInstanceOf(AuditLogger::class, $service->audit);
        $this->assertInstanceOf(FileLogger::class, $service->primary);
        $this->assertSame([$container->get(PushNotifier::class), $container->get(EmailNotifier::class)], $service->notifiers);
    }

    // ── §6.3 union, intersection and DNF types ─────────────────────────────

    public function testUnionResolvesItsOnlyRegisteredMember(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(NullLogger::class)
            ->transient(NeedsUnion::class)
            ->transient(NeedsOptionalUnion::class)
            ->build();

        $this->assertInstanceOf(NullLogger::class, $container->get(NeedsUnion::class)->logger);
        $this->assertInstanceOf(NullLogger::class, $container->get(NeedsOptionalUnion::class)->logger);
    }

    public function testNullableUnionWithoutCandidatesReceivesNull(): void
    {
        $container = (new TestServiceCollection())->transient(NeedsOptionalUnion::class)->build();

        $this->assertNull($container->get(NeedsOptionalUnion::class)->logger);
    }

    public function testIntersectionNeedsAnImplementationSatisfyingEveryMember(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Readable::class, Stream::class)
            ->transient(NeedsStream::class)
            ->build();

        $this->assertInstanceOf(Stream::class, $container->get(NeedsStream::class)->stream);
    }

    public function testIntersectionIgnoresPartialImplementations(): void
    {
        $services = (new TestServiceCollection())
            ->singleton(Readable::class, ReadOnlyStream::class)
            ->transient(NeedsStream::class);

        $this->expectExceptionMessage('no registered service satisfies');
        $services->build();
    }

    public function testDnfCombinesAlternatives(): void
    {
        $container = (new TestServiceCollection())
            ->singleton(Writable::class, Stream::class)
            ->transient(NeedsDnf::class)
            ->build();

        $this->assertInstanceOf(Stream::class, $container->get(NeedsDnf::class)->value);
    }

    // ── §6.4 value providers and enums ─────────────────────────────────────

    public function testEnvValueProviderConvertsToTheDeclaredType(): void
    {
        $_ENV['ZANA_TEST_PORT'] = '8080';
        $_ENV['ZANA_TEST_DEBUG'] = 'Yes';
        putenv('ZANA_TEST_COLOR=red');
        $_ENV['ZANA_TEST_RATIO'] = '0.5';

        $settings = $this->envContainer(EnvSettings::class)->get(EnvSettings::class);

        $this->assertSame([8080, true, Color::Red, 0.5, 'zana'], [$settings->port, $settings->debug, $settings->color, $settings->ratio, $settings->name]);
    }

    public function testEnvValueProviderConvertsIntBackedEnums(): void
    {
        $_ENV['ZANA_TEST_LEVEL'] = '2';

        $this->assertSame(Level::High, $this->envContainer(EnvLevel::class)->get(EnvLevel::class)->level);
    }

    public function testInvalidEnvironmentValuesAreReported(): void
    {
        $_ENV['ZANA_TEST_PORT'] = 'eighty';

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('Environment variable ZANA_TEST_PORT="eighty" is not a valid int.');
        $this->envContainer(EnvSettings::class)->get(EnvSettings::class);
    }

    public function testMissingRequiredEnvironmentVariableIsReported(): void
    {
        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('Environment variable ZANA_TEST_UNSET is not set (needed by ' . EnvRequired::class . ').');
        $this->envContainer(EnvRequired::class)->get(EnvRequired::class);
    }

    public function testCustomProvidersReceiveContextAndEnumValuesAreConverted(): void
    {
        $provider = new ArraySettingsProvider(['mode' => 'strict', 'level' => 1]);
        $container = (new TestServiceCollection())
            ->addParameterProvider($provider)
            ->transient(UsesSettings::class, origin: 'module:Settings')
            ->build();

        $service = $container->get(UsesSettings::class);

        $this->assertSame(['strict', Level::Low], [$service->mode, $service->level]);
        $this->assertSame(UsesSettings::class, $provider->contexts[0]->descriptor?->service);
        $this->assertSame([UsesSettings::class . ' (module:Settings)'], $provider->contexts[0]->chain);
    }

    public function testProviderValuesMustMatchTheDeclaredType(): void
    {
        $container = (new TestServiceCollection())
            ->addParameterProvider(new ArraySettingsProvider(['mode' => 42, 'level' => 1]))
            ->transient(UsesSettings::class)
            ->build();

        $this->expectException(ResolutionException::class);
        $container->get(UsesSettings::class);
    }

    public function testInvalidEnumValuesAreReported(): void
    {
        $container = (new TestServiceCollection())
            ->addParameterProvider(new ArraySettingsProvider(['mode' => 'x', 'level' => 9]))
            ->transient(UsesSettings::class)
            ->build();

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('9');
        $container->get(UsesSettings::class);
    }

    /**
     * @param class-string $class
     */
    private function envContainer(string $class): \Zana\Container\Contracts\ContainerInterface
    {
        return (new TestServiceCollection())
            ->addParameterProvider(new EnvValueProvider())
            ->transient($class)
            ->build();
    }
}
