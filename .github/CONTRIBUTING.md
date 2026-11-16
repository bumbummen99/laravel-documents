# Contribution Guide

Thank you for considering contributing to Laravel Documents! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

PHPStan is temporarily constrained below 2.3.6 because Pest's type-coverage
plugin calls an API removed in that release. Track the upstream fix in
[pestphp/pest-plugin-type-coverage#75](https://github.com/pestphp/pest-plugin-type-coverage/pull/75).
Remove the upper bound once compatible plugins are available for both supported
Pest versions. CI resolves dependencies afresh, so a local lockfile alone does
not prevent this incompatibility.

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all checks and tests using PHP 8.4+, Pest 5, and type-coverage plugin 5.0.1
or newer:

```bash
composer test
```

CI runs formatting and type coverage once in the dedicated latest-stable Checks job using PHP 8.5,
Laravel 13, Pest 5, and the latest compatible type-coverage plugin 5.x
(at least 5.0.1, which includes the cache-locking fix). Runtime tests and
PHPStan retain their existing compatibility matrices;
the older plugin is not executed for type coverage in those jobs.
All runtime-test matrices wait for Checks to pass and are skipped if it fails.
The type-coverage threshold remains 100%.

Type coverage is a latest-stack-only check; no serialization override is needed.
For local PHP 8.3/Pest 4 compatibility testing, run the checks without type
coverage instead of `composer test`:

```bash
composer analyse
composer lint:check
composer test:unit
```

Run mutation testing separately to check whether the tests detect changes in
the package source:

```bash
composer test:mutation
```

Pest includes its mutation plugin. Enable PCOV or Xdebug's coverage mode
(`XDEBUG_MODE=coverage`) before running it. The command mutates all source files
configured in `phpunit.xml.dist`, including code for optional integrations.
Tests requiring an unavailable integration are skipped, so its implementation
can still have uncovered mutations.

Pest manages the parallel workers. Do not pass `--processes` to the current
mutation runner: it forwards that option to child PHPUnit processes, which
reject it and falsely make mutations appear detected.

For a focused run, use
`vendor/bin/pest --mutate --parallel --class='SkyRaptor\LaravelDocuments\Pdf'`.
Do not add `--everything` to a focused run; it overrides the class selection.
Improve observable behavior assertions rather than ignoring mutations merely
to raise the score.
