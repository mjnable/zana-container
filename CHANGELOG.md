# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Dependency injection container for the Zana framework. Registrations are validated as a whole when the container is built, so wiring errors surface at startup instead of on the first request that needs a service.

Upgrading from 1.x (`zana/container`): the container is strict: only registered services resolve, and `build()` validates the whole graph. See [Upgrading from 1.x](https://github.com/mjnable/zana-docs/blob/main/upgrading.md).

Documentation: [the container guide](https://github.com/mjnable/zana-docs/blob/main/guides/container.md).
