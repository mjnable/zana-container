<?php

declare(strict_types=1);

namespace Zana\Container;

use Closure;
use Zana\Container\Contracts\CapabilitiesInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Internal\AttributeReader;
use Zana\Container\Internal\BuildValidator;
use Zana\Container\Internal\Capabilities;
use Zana\Container\Internal\Engine;
use Zana\Container\Internal\Registry;
use Zana\Container\Internal\Scanner;

/**
 * Records service registrations and builds a validated container from them.
 *
 * Registering the same service again appends: get() returns the last
 * registration and getAll() returns all of them.
 */
class ServiceCollection implements ServiceCollectionInterface
{
    private const CAPABILITY_NAME = '/^[a-z][a-z0-9.-]*$/';

    /** @var list<ServiceDescriptor> */
    private array $descriptors = [];

    /** @var list<ServiceDescriptor> Registrations found by scan(), placed before explicit ones */
    private array $scanned = [];

    /** @var array<string, string> alias => target */
    private array $aliases = [];

    /** @var array<string, list<string>> tag => services */
    private array $tags = [];

    /** @var array<string, list<string>> tag => services, from scanned attributes */
    private array $scannedTags = [];

    /** @var array<string, list<class-string|Closure>> service => decorators */
    private array $decorators = [];

    /** @var list<array{string, string, ?string}> directory, namespace, origin */
    private array $pendingScans = [];

    /** @var list<BuildError> */
    private array $scanErrors = [];

    /** @var list<ParameterValueProviderInterface> */
    private array $providers = [];

    /** @var array<string, list<string>> capability => origins */
    private array $capabilities = [];

    /** @var list<array{string, ?string}> capability, origin */
    private array $requirements = [];

    /** @var list<array{string, Closure}> */
    private array $capabilityCallbacks = [];

    private bool $inCapabilityCallback = false;

    private bool $built = false;

    /**
     * @param list<string> $activeProfiles Profiles enabling profile() and #[Profile] registrations
     */
    public function __construct(private readonly array $activeProfiles = [])
    {
    }

    public function singleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Singleton, $key, $origin);
    }

    public function scoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Scoped, $key, $origin);
    }

    public function transient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Transient, $key, $origin);
    }

    public function trySingleton(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->singleton($service, $implementation, $key, $origin);
    }

    public function tryScoped(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->scoped($service, $implementation, $key, $origin);
    }

    public function tryTransient(string $service, string|Closure|null $implementation = null, ?string $key = null, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->transient($service, $implementation, $key, $origin);
    }

    public function instance(string $service, object $instance, ?string $key = null, ?string $origin = null): static
    {
        $this->assertNotBuilt();

        $this->descriptors[] = new ServiceDescriptor(
            service: $service,
            lifetime: ServiceLifetime::Singleton,
            instance: $instance,
            key: $key,
            origin: $origin,
        );

        return $this;
    }

    public function replace(string $service, string|Closure $implementation, ?string $key = null, ?ServiceLifetime $lifetime = null, ?string $origin = null): static
    {
        $this->assertNotBuilt();

        $replaced = array_values(array_filter(
            $this->descriptors,
            static fn (ServiceDescriptor $d): bool => $d->service === $service && $d->key === $key
        ));

        if ($replaced === []) {
            throw ContainerException::nothingToReplace($service, $key);
        }

        $this->removeRegistrations($service, $key);

        return $this->add($service, $implementation, $lifetime ?? $replaced[array_key_last($replaced)]->lifetime, $key, $origin);
    }

    public function remove(string $service, ?string $key = null): static
    {
        $this->assertNotBuilt();

        $this->removeRegistrations($service, $key);

        if ($key === null) {
            unset($this->aliases[$service], $this->decorators[$service]);

            foreach ($this->tags as $tag => $services) {
                $this->tags[$tag] = array_values(array_diff($services, [$service]));
            }
        }

        return $this;
    }

    public function alias(string $alias, string $target): static
    {
        $this->assertNotBuilt();

        if ($alias === $target) {
            throw ContainerException::selfAlias($alias);
        }

        $this->aliases[$alias] = $target;

        return $this;
    }

    public function tag(string $tag, string ...$services): static
    {
        $this->assertNotBuilt();

        foreach ($services as $service) {
            if (!in_array($service, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $service;
            }
        }

        return $this;
    }

    public function decorate(string $service, string|Closure $decorator): static
    {
        $this->assertNotBuilt();

        /** @var class-string|Closure $decorator */
        $this->decorators[$service][] = $decorator;

        return $this;
    }

    public function when(bool|Closure $condition, Closure $register): static
    {
        $this->assertNotBuilt();

        if ($condition instanceof Closure ? (bool) $condition() : $condition) {
            $register($this);
        }

        return $this;
    }

    public function profile(string $profile, Closure $register): static
    {
        return $this->when(in_array($profile, $this->activeProfiles, true), $register);
    }

    public function activeProfiles(): array
    {
        return $this->activeProfiles;
    }

    public function addAttributed(string ...$classes): static
    {
        $this->assertNotBuilt();

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new ContainerException("Cannot register {$class} from attributes: the class does not exist.");
            }

            $registration = AttributeReader::registration(new \ReflectionClass($class), $this->activeProfiles, null);
            if ($registration === null) {
                continue;
            }

            [$descriptor, $tags] = $registration;
            $this->descriptors[] = $descriptor;
            foreach ($tags as $tag) {
                $this->tag($tag, $descriptor->service);
            }
        }

        return $this;
    }

    public function scan(string $directory, string $namespace, ?string $origin = null): static
    {
        $this->assertNotBuilt();

        $this->pendingScans[] = [$directory, $namespace, $origin];

        return $this;
    }

    public function addParameterProvider(ParameterValueProviderInterface $provider): static
    {
        $this->assertNotBuilt();

        $this->providers[] = $provider;

        return $this;
    }

    public function provide(string $capability, ?string $origin = null): static
    {
        $this->assertCapabilityCall($capability, 'provide');

        $origin ??= '(unknown)';
        if (!in_array($origin, $this->capabilities[$capability] ?? [], true)) {
            $this->capabilities[$capability][] = $origin;
        }

        return $this;
    }

    public function requires(string $capability, ?string $origin = null): static
    {
        $this->assertCapabilityCall($capability, 'requires');

        $this->requirements[] = [$capability, $origin];

        return $this;
    }

    public function whenCapability(string $capability, Closure $register): static
    {
        $this->assertCapabilityCall($capability, 'whenCapability');

        $this->capabilityCallbacks[] = [$capability, $register];

        return $this;
    }

    public function has(string $service, ?string $key = null): bool
    {
        return $this->isRegistered($service, $key) || ($key === null && isset($this->aliases[$service]));
    }

    public function descriptors(): array
    {
        $this->runPendingScans();

        return [...$this->scanned, ...$this->descriptors];
    }

    public function build(): ContainerInterface
    {
        $this->assertNotBuilt();

        $this->runPendingScans();
        $this->applyCapabilityCallbacks();

        $descriptors = [...$this->scanned, ...$this->descriptors];
        $errors = [...$this->scanErrors, ...$this->capabilityErrors($descriptors)];

        $descriptors[] = new ServiceDescriptor(
            CapabilitiesInterface::class,
            ServiceLifetime::Singleton,
            instance: new Capabilities($this->capabilities),
            origin: 'zana/container',
        );

        $registry = new Registry($descriptors, $this->aliases, $this->mergedTags(), $this->decorators);
        $result = (new BuildValidator($registry, $this->providers))->validate();
        $errors = [...$errors, ...$result->errors];

        if ($errors !== []) {
            throw ContainerBuildException::withErrors($errors);
        }

        $this->built = true;

        return new Container($registry, new Engine($registry, $result->plans, $result->decoratorPlans, $this->providers));
    }

    private function add(string $service, string|Closure|null $implementation, ServiceLifetime $lifetime, ?string $key, ?string $origin): static
    {
        $this->assertNotBuilt();

        $this->descriptors[] = new ServiceDescriptor(
            service: $service,
            lifetime: $lifetime,
            implementation: $implementation instanceof Closure ? null : ($implementation ?? $service),
            factory: $implementation instanceof Closure ? $implementation : null,
            key: $key,
            origin: $origin,
        );

        return $this;
    }

    private function runPendingScans(): void
    {
        foreach ($this->pendingScans as [$directory, $namespace, $origin]) {
            [$descriptors, $tags, $errors] = Scanner::scan($directory, $namespace, $origin, $this->activeProfiles);

            array_push($this->scanned, ...$descriptors);
            array_push($this->scanErrors, ...$errors);
            foreach ($tags as $tag => $services) {
                foreach ($services as $service) {
                    if (!in_array($service, $this->scannedTags[$tag] ?? [], true)) {
                        $this->scannedTags[$tag][] = $service;
                    }
                }
            }
        }

        $this->pendingScans = [];
    }

    /**
     * Run whenCapability() callbacks once, after every other registration.
     */
    private function applyCapabilityCallbacks(): void
    {
        $callbacks = $this->capabilityCallbacks;
        $this->capabilityCallbacks = [];

        $this->inCapabilityCallback = true;
        try {
            foreach ($callbacks as [$capability, $register]) {
                if (isset($this->capabilities[$capability])) {
                    $register($this);
                }
            }
        } finally {
            $this->inCapabilityCallback = false;
        }
    }

    /**
     * B14: unmet requirements, and an explicit CapabilitiesInterface registration.
     *
     * @param list<ServiceDescriptor> $descriptors
     * @return list<BuildError>
     */
    private function capabilityErrors(array $descriptors): array
    {
        $errors = [];

        foreach ($this->requirements as [$capability, $origin]) {
            if (!isset($this->capabilities[$capability])) {
                $errors[] = new BuildError(
                    'B14',
                    sprintf('capability \'%s\' is required by %s but nothing provides it', $capability, $origin ?? 'an unknown origin'),
                    origin: $origin,
                );
            }
        }

        foreach ($descriptors as $descriptor) {
            if ($descriptor->service === CapabilitiesInterface::class) {
                $errors[] = new BuildError(
                    'B14',
                    CapabilitiesInterface::class . ' is registered by the container itself and cannot be registered explicitly',
                    CapabilitiesInterface::class,
                    $descriptor->key,
                    $descriptor->origin,
                );
            }
        }

        return $errors;
    }

    /**
     * @return array<string, list<string>>
     */
    private function mergedTags(): array
    {
        $tags = $this->scannedTags;
        foreach ($this->tags as $tag => $services) {
            foreach ($services as $service) {
                if (!in_array($service, $tags[$tag] ?? [], true)) {
                    $tags[$tag][] = $service;
                }
            }
        }

        return $tags;
    }

    private function isRegistered(string $service, ?string $key): bool
    {
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->service === $service && $descriptor->key === $key) {
                return true;
            }
        }

        return false;
    }

    private function removeRegistrations(string $service, ?string $key): void
    {
        $this->descriptors = array_values(array_filter(
            $this->descriptors,
            static fn (ServiceDescriptor $d): bool => !($d->service === $service && $d->key === $key)
        ));
    }

    private function assertCapabilityCall(string $capability, string $method): void
    {
        $this->assertNotBuilt();

        if ($this->inCapabilityCallback) {
            throw new ContainerException("{$method}() cannot be called inside a whenCapability() callback.");
        }

        if (preg_match(self::CAPABILITY_NAME, $capability) !== 1) {
            throw new ContainerException(
                "Invalid capability name '{$capability}': use lower-case letters, digits, dots and dashes, starting with a letter."
            );
        }
    }

    /**
     * A try* call that registers nothing must still respect the frozen state.
     */
    private function unlessBuilt(): static
    {
        $this->assertNotBuilt();

        return $this;
    }

    private function assertNotBuilt(): void
    {
        if ($this->built) {
            throw ContainerException::alreadyBuilt();
        }
    }
}
