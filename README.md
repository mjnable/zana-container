# Zana Container

Dependency injection container for the Zana framework. Registrations are
validated as a whole when the container is built, so wiring errors surface at
startup instead of on the first request that needs a service.

Requires PHP 8.5 or later. Implements [PSR-11](https://www.php-fig.org/psr/psr-11/).

## Install

```
composer require mj-zana/container
```

## Usage

```php
use Zana\Container\ServiceCollection;

$services = new ServiceCollection();
$services->singleton(LoggerInterface::class, FileLogger::class);
$services->scoped(UnitOfWork::class);
$services->transient(CalculateBmiHandler::class);

$container = $services->build(); // validates everything, then freezes the collection

$scope = $container->createScope(); // e.g. one per HTTP request
$handler = $scope->get(CalculateBmiHandler::class);
// ...
$scope->dispose();
```

Only registered services are created. `get()` of an unregistered class throws
`NotFoundException`, even if the class exists.

## Lifetimes

| Lifetime | Instances | From the root container | From a scope |
|---|---|---|---|
| `singleton` | one per container | created and cached in the root | the root instance |
| `scoped` | one per scope | throws `ScopeException` | created and cached in that scope |
| `transient` | new on every resolution | allowed | allowed |

A singleton's dependencies always come from the root container, so a singleton
can never capture a scoped service. `build()` rejects a singleton that depends
on a scoped service, directly or through transients.

## Registration

```php
$services->singleton(Clock::class);                                   // self-registration
$services->singleton(LoggerInterface::class, FileLogger::class);      // class
$services->transient(Connection::class, fn ($resolver) => new Connection($dsn)); // factory
$services->instance(Config::class, $config);                          // existing object, never disposed

$services->singleton(CacheInterface::class, RedisCache::class, key: 'redis'); // keyed
$services->trySingleton(LoggerInterface::class, NullLogger::class);   // only if absent (package defaults)
$services->replace(LoggerInterface::class, SyslogLogger::class);      // keeps tags, aliases, decorators
$services->remove(LoggerInterface::class);

$services->alias('logger', LoggerInterface::class);
$services->tag('notifiers', EmailNotifier::class, SmsNotifier::class);
$services->decorate(LoggerInterface::class, PrefixLogger::class);     // or a closure
```

Registering a service again appends: `get()` returns the last registration and
`getAll()` returns every unkeyed one in order. `origin:` (e.g. `module:Patients`)
labels a registration in error messages.

## Resolution

Constructor parameters are resolved in this order:

1. `Psr\Container\ContainerInterface` or `ServiceResolverInterface`: the owning resolver
2. a single registered class or interface type: `get(type)`
3. a variadic class or interface type: `getAll(type)` (may be empty)
4. otherwise the declared default, then `null` if nullable. Anything else is a build error.

Scalars, such as a DSN, come from factory registrations.

```php
$container->get(LoggerInterface::class);
$container->getKeyed(CacheInterface::class, 'redis');
$container->getAll(NotifierInterface::class);
$container->tagged('notifiers');
```

## Attributes and scanning

```php
#[Singleton(service: LoggerInterface::class, tags: ['loggers'])]
#[Profile('production')]
final class FileLogger implements LoggerInterface { /* ... */ }

$services = new ServiceCollection(activeProfiles: ['production']);
$services->addAttributed(FileLogger::class);                    // one class
$services->scan(__DIR__ . '/src/Services', 'App\\Services', origin: 'module:App'); // a PSR-4 directory
```

Scanning only registers classes that carry `#[Singleton]`, `#[Scoped]` or
`#[Transient]`. Scanned registrations come before explicit ones, so an explicit
registration of the same service wins.

`when($condition, fn ($services) => ...)` and `profile('testing', fn ($services) => ...)`
register services conditionally.

## Injection attributes and values

```php
final class ReportService
{
    #[Inject] private Clock $clock;                               // property injection

    public function __construct(
        #[Inject(key: 'redis')] private CacheInterface $cache,     // a keyed registration
        #[Tagged('exporters')] private iterable $exporters,        // every member of a tag
        #[Env('REPORT_TIMEOUT')] private int $timeout = 30,        // an environment variable
    ) {}

    #[Inject]
    public function useLogger(LoggerInterface $logger): void {}   // method injection
}

$services->addParameterProvider(new EnvValueProvider());
```

Values for `#[Env]` come from `EnvValueProvider`, which converts them to `int`,
`float`, `bool`, `string` or a backed enum. Other packages add their own value
attributes, such as `#[Config]`, by implementing `ParameterValueProviderInterface`.

A union, intersection or DNF parameter resolves when exactly one registered
service satisfies it. If several do, the build fails (B10); pick one with `#[Inject]`.

`call()` invokes a callable with its parameters resolved:

```php
$container->call([ReportController::class, 'show'], ['id' => 7]);
```

## Capabilities

Packages declare features they provide. Others can require them, or register
extra services only when they're present:

```php
$services->provide('reporting', origin: 'package:mj-zana/reporting');
$services->requires('reporting', origin: 'module:Finance');           // build fails (B14) if missing
$services->whenCapability('reporting', fn ($s) => $s->tag('routes', ReportRoutes::class));

$container->get(CapabilitiesInterface::class)->has('reporting');
```

A capability is presence metadata only; it never resolves to an object.

## Build validation

`build()` reports every problem at once in a `ContainerBuildException`, whose
`errors()` method returns a list of `BuildError` objects, each with a code:

| Code | Rule |
|---|---|
| B1 | the implementation is an instantiable class |
| B2 | the implementation (or instance) satisfies the service type |
| B3 | every constructor parameter can be resolved |
| B4 | no circular constructor dependencies |
| B5 | no singleton depends on a scoped service |
| B6 | aliases point to registered services, without cycles |
| B7 | tagged services are registered |
| B8 | decorated services exist and class decorators are valid |
| B9 | `#[Inject]` targets are registered |
| B10 | union and intersection types are not ambiguous |
| B11 | attributes are used correctly, and value attributes have a provider |
| B13 | scan directories exist and their files declare the expected classes |
| B14 | required capabilities are provided |

**Limitation:** factory and instance registrations are opaque to B3–B5. Their
dependencies are only checked when they are resolved.

## Disposal

Services implementing `Zana\Container\Contracts\Disposable` are disposed by the
resolver that owns them:

- **A scope** disposes its scoped services.
- **The root container** first disposes its open scopes, then its singletons.

Disposal runs in reverse creation order, and decorators are disposed before what
they wrap. Transients and `instance()` objects are never disposed. A scope that
is dropped without `dispose()` is garbage-collected and **not** disposed, so
always dispose scopes explicitly.

## Development

```
composer install
composer test       # PHPUnit
composer analyse    # PHPStan level 8
```

The specification lives in the Zana design docs (`packages/container.md`).
Nested scopes, interceptors, lazy services, the service graph and compilation
are planned for later M3 steps.

## License

MIT — see [LICENSE](LICENSE).
