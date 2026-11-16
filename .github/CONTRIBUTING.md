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

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
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
