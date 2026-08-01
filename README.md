# Zana Container

Dependency Injection container for the Zana framework.

## Features

- **`ServiceCollection`** — fluent service registration (transient, singleton, scoped, instance)
- **`ServiceProvider`** — DI container with `get()`, `has()`, `tryGet()`, auto-wiring, and constructor resolution
- **`ServiceScope`** — scoped service lifetime management
- **`ConstructorResolver`** / **`ParameterResolver`** — automatic constructor and method parameter resolution
- **`CircularDependencyException`** / **`ContainerException`** / **`NotFoundException`** / **`ResolutionException`** — typed error handling

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

