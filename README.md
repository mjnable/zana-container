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
Attributes, scanning, capabilities, nested scopes, interceptors, lazy services,
the service graph and compilation are planned for later M3 steps.

## License

MIT — see [LICENSE](LICENSE).
