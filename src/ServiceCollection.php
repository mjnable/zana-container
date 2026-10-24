<?php

declare(strict_types=1);

namespace Zana\Container;

use Closure;
use Zana\Container\Contracts\CapabilitiesInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ParameterValueProviderInterface;
use Zana\Container\Contracts\ResolutionInterceptorInterface;
use Zana\Container\Contracts\ScopeObserverInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Exception\ContainerBuildException;
use Zana\Container\Exception\ContainerException;
use Zana\Container\Compiled\CompiledContainer;
use Zana\Container\Exception\StaleCompilationException;
use Zana\Container\Graph\ServiceGraph;
use Zana\Container\Internal\ArrayPlanSource;
use Zana\Container\Internal\AttributeReader;
use Zana\Container\Internal\CompiledPlanSource;
use Zana\Container\Internal\GraphBuilder;
use Zana\Container\Internal\PlanCodec;
use Zana\Container\Internal\PlanSource;
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

    /** @var list<array{string, string, ?string}> Every scan() call, kept for the fingerprint */
    private array $scanRequests = [];

    /** @var list<BuildError> */
    private array $scanErrors = [];

    /** @var list<ParameterValueProviderInterface> */
    private array $providers = [];

    /** @var list<ResolutionInterceptorInterface> */
    private array $interceptors = [];

    /** @var list<ScopeObserverInterface> */
    private array $observers = [];

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

    public function singleton(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Singleton, $key, $origin, $lazy);
    }

    public function scoped(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Scoped, $key, $origin, $lazy);
    }

    public function transient(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->add($service, $implementation, ServiceLifetime::Transient, $key, $origin, $lazy);
    }

    public function trySingleton(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->singleton($service, $implementation, $key, $lazy, $origin);
    }

    public function tryScoped(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->scoped($service, $implementation, $key, $lazy, $origin);
    }

    public function tryTransient(string $service, string|Closure|null $implementation = null, ?string $key = null, bool $lazy = false, ?string $origin = null): static
    {
        return $this->isRegistered($service, $key) ? $this->unlessBuilt() : $this->transient($service, $implementation, $key, $lazy, $origin);
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
        $this->scanRequests[] = [str_replace("\\", "/", rtrim($directory, "/\\")), trim($namespace, "\\"), $origin];

        return $this;
    }

    public function addParameterProvider(ParameterValueProviderInterface $provider): static
    {
        $this->assertNotBuilt();

        $this->providers[] = $provider;

        return $this;
    }

    public function addInterceptor(ResolutionInterceptorInterface $interceptor): static
    {
        $this->assertNotBuilt();

        $this->interceptors[] = $interceptor;

        return $this;
    }

    public function addScopeObserver(ScopeObserverInterface $observer): static
    {
        $this->assertNotBuilt();

        $this->observers[] = $observer;

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

    public function analyze(): BuildReport
    {
        $this->assertNotBuilt();

        [$registry, $result, $errors] = $this->prepare();

        return new BuildReport(
            GraphBuilder::build($registry, $result->plans, $result->decoratorPlans, $this->capabilities),
            $errors,
        );
    }

    public function build(?string $compiled = null): ContainerInterface
    {
        $this->assertNotBuilt();

        if ($compiled !== null) {
            return $this->buildCompiled($compiled);
        }

        [$registry, $result, $errors] = $this->prepare();

        if ($errors !== []) {
            throw ContainerBuildException::withErrors($errors);
        }

        $this->built = true;
        $capabilities = $this->capabilities;

        return new Container(
            $registry,
            $this->engine($registry, new ArrayPlanSource($result->plans, $result->decoratorPlans)),
            static fn (): ServiceGraph => GraphBuilder::build($registry, $result->plans, $result->decoratorPlans, $capabilities),
        );
    }

    /**
     * Validate and gather what ContainerCompiler writes into a compiled class.
     *
     * @internal Used by ContainerCompiler.
     * @return array{fingerprint: string, data: array<string, mixed>}
     * @throws ContainerBuildException If the registrations are invalid
     */
    public function compilation(): array
    {
        $this->assertNotBuilt();

        [$registry, $result, $errors] = $this->prepare();
        if ($errors !== []) {
            throw ContainerBuildException::withErrors($errors);
        }

        return [
            'fingerprint' => $this->fingerprint(),
            'data' => [
                'scanned' => array_map(
                    static fn (ServiceDescriptor $d): array => [$d->service, $d->lifetime->name, (string) $d->implementation, $d->key, $d->lazy, $d->origin],
                    $this->scanned
                ),
                'scannedTags' => $this->scannedTags,
                'plans' => array_map(PlanCodec::encode(...), $result->plans),
                'decoratorPlans' => array_map(PlanCodec::encode(...), $result->decoratorPlans),
                'graph' => GraphBuilder::build($registry, $result->plans, $result->decoratorPlans, $this->capabilities)->toArray(),
            ],
        ];
    }

    /**
     * @param class-string|string $compiled
     */
    private function buildCompiled(string $compiled): ContainerInterface
    {
        if (!is_subclass_of($compiled, CompiledContainer::class)) {
            throw StaleCompilationException::notCompiled($compiled);
        }

        $this->applyCapabilityCallbacks();

        $fingerprint = $this->fingerprint();
        if ($compiled::FINGERPRINT !== $fingerprint) {
            throw StaleCompilationException::for($compiled, $compiled::FINGERPRINT, $fingerprint);
        }

        $data = $compiled::data();

        $this->pendingScans = [];
        $this->scanned = array_map(
            static fn (array $d): ServiceDescriptor => new ServiceDescriptor(
                $d[0],
                constant(ServiceLifetime::class . '::' . $d[1]),
                implementation: $d[2],
                key: $d[3],
                lazy: $d[4],
                origin: $d[5],
            ),
            $data['scanned']
        );
        $this->scannedTags = $data['scannedTags'];

        $registry = $this->registry([...$this->scanned, ...$this->descriptors]);
        $this->built = true;

        $graph = ServiceGraph::fromArray($data['graph']);

        return new Container(
            $registry,
            $this->engine($registry, new CompiledPlanSource($data['plans'], $data['decoratorPlans'])),
            static fn (): ServiceGraph => $graph,
        );
    }

    /**
     * Run scans and capability callbacks, then validate.
     *
     * @return array{Registry, Internal\BuildResult, list<BuildError>}
     */
    private function prepare(): array
    {
        $this->runPendingScans();
        $this->applyCapabilityCallbacks();

        $descriptors = [...$this->scanned, ...$this->descriptors];
        $errors = [...$this->scanErrors, ...$this->capabilityErrors($descriptors)];

        $registry = $this->registry($descriptors);
        $result = (new BuildValidator($registry, $this->providers))->validate();

        return [$registry, $result, [...$errors, ...$result->errors]];
    }

    /**
     * @param list<ServiceDescriptor> $descriptors
     */
    private function registry(array $descriptors): Registry
    {
        $descriptors[] = new ServiceDescriptor(
            CapabilitiesInterface::class,
            ServiceLifetime::Singleton,
            instance: new Capabilities($this->capabilities),
            origin: 'zana/container',
        );

        return new Registry($descriptors, $this->aliases, $this->mergedTags(), $this->decorators);
    }

    private function engine(Registry $registry, PlanSource $plans): Engine
    {
        return new Engine($registry, $plans, $this->providers, $this->interceptors, $this->observers);
    }

    /**
     * Identifies the registrations a compiled container was generated from
     * (specification §11). Scan requests count, not their results; closures
     * and instances count by kind and class, not by identity.
     */
    private function fingerprint(): string
    {
        $describe = static fn (ServiceDescriptor $d): array => [
            $d->service,
            $d->key,
            $d->lifetime->name,
            match (true) {
                $d->instance !== null => 'instance:' . get_class($d->instance),
                $d->factory !== null => 'factory',
                default => 'class:' . $d->implementation,
            },
            $d->lazy,
            $d->origin,
        ];

        return hash('xxh128', serialize([
            ServiceGraph::SCHEMA,
            array_map($describe, $this->descriptors),
            $this->aliases,
            $this->tags,
            array_map(
                static fn (array $decorators): array => array_map(static fn (string|Closure $d): string => $d instanceof Closure ? 'closure' : $d, $decorators),
                $this->decorators
            ),
            $this->scanRequests,
            $this->activeProfiles,
            array_map('get_class', $this->providers),
            array_map('get_class', $this->interceptors),
            array_map('get_class', $this->observers),
            $this->capabilities,
            $this->requirements,
        ]));
    }

    private function add(string $service, string|Closure|null $implementation, ServiceLifetime $lifetime, ?string $key, ?string $origin, bool $lazy = false): static
    {
        $this->assertNotBuilt();

        $this->descriptors[] = new ServiceDescriptor(
            service: $service,
            lifetime: $lifetime,
            implementation: $implementation instanceof Closure ? null : ($implementation ?? $service),
            factory: $implementation instanceof Closure ? $implementation : null,
            key: $key,
            lazy: $lazy,
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
