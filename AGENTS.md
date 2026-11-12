# Laravel Documents

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `skyraptor/laravel-documents`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Architecture and Lifecycle

- Design components around cohesive responsibilities and explicit lifecycle boundaries, not merely conceptual grouping. Identify their states, transitions, invariants, and operation prerequisites before introducing or changing lifecycle-related abstractions.
- Avoid partially initialized objects and implicit ordering requirements where practical. Make lifecycle prerequisites explicit through APIs, types, composition, or dedicated coordinators rather than scattering state checks and assumptions across methods.
- Keep state transitions predictable and independently testable. Account for invalid transitions, failures, retries, recovery, and cleanup where applicable.
- Prefer eliminating unnecessary states and simplifying transitions over introducing abstractions or design patterns solely to model them. The resulting architecture should be easy to understand, test, maintain, and represent in UML.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
