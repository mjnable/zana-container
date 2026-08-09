# Zana Container

Dependency Injection container for the Zana framework.

## Features

- **`ServiceCollection`** — fluent service registration (transient, singleton, scoped, instance)
- **`ServiceProvider`** — DI container with `get()`, `has()`, `tryGet()`, auto-wiring, and constructor resolution
- **`ServiceScope`** — scoped service lifetime management
- **`ConstructorResolver`** / **`ParameterResolver`** — automatic constructor and method parameter resolution
- **`CircularDependencyException`** / **`ContainerException`** / **`NotFoundException`** / **`ResolutionException`** — typed error handling
- **`ScopeMetadata`** (in `Zana\Container\Scopes`) — concrete scope metadata implementing the complete `ScopeMetadataInterface` from `zana/contracts`
- **Scope contracts** — `ScopeMetadataInterface`, `ScopeType`, `DisposableInterface`, `ServiceLifetime` all live in `zana/contracts` (`Zana\Contracts\Container`) so other packages can consume them without depending on `zana/container`
- **`ServiceDescriptor`** (in `Zana\Container\Descriptors`) — container-internal value object describing a service registration. It is **not** a framework contract; no `Zana\Contracts\*` type references it, so it stays in the container package (see the decisive-test rule in `docs/PACKAGES.md`).

## Install

```
composer require zana/container
```

## Usage

```php
use Zana\Container\ServiceCollection;

$services = new ServiceCollection();
$services->singleton(LoggerInterface::class, FileLogger::class);
$services->transient(Calculator::class);

$container = $services->build();

$app = $container->get(Calculator::class);
```

## Testing

```
vendor/bin/phpunit packages/container/tests
```

## License

MIT — see [LICENSE](LICENSE).
