<div align="center">
    <h1>Laravel Documents</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/skyraptor/laravel-documents"><img src="https://img.shields.io/packagist/v/skyraptor/laravel-documents.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-documents"><img src="https://img.shields.io/packagist/php-v/skyraptor/laravel-documents.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-documents"><img src="https://badge.laravel.cloud/badge/skyraptor/laravel-documents?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/skyraptor/laravel-documents/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/skyraptor/laravel-documents/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/skyraptor/laravel-documents"><img src="https://img.shields.io/packagist/dt/skyraptor/laravel-documents.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A document system for your Laravel application.

## Installation

You can install the package via Composer:

```bash
composer require skyraptor/laravel-documents
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-documents"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-documents-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-documents-migrations"
php artisan migrate
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-documents-lang"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Documents! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Patrick Henninger](https://github.com/skyraptor)
- [All Contributors](../../contributors)

## License

Laravel Documents is licensed under the [AGPL-3.0-only](LICENSE.md) with the
[Library Integration Exception](COPYING.EXCEPTION). Independent applications may
retain their own license, including proprietary terms; the package and covered
modifications remain subject to AGPL obligations. See [COPYING](COPYING) for the
full license.
