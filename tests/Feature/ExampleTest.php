<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\LaravelDocuments;

it('resolves the singleton', function () {
    expect(app(LaravelDocuments::class))->toBeInstanceOf(LaravelDocuments::class);
});

it('returns the same instance from the container', function () {
    expect(app(LaravelDocuments::class))->toBe(app(LaravelDocuments::class));
});

it('merges the package config', function () {
    expect(config('laravel-documents.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-documents::messages.placeholder'))->toBe('LaravelDocuments placeholder translation.');
});

it('registers the artisan command', function () {
    $this->artisan('laravel-documents:placeholder')
        ->expectsOutputToContain('LaravelDocuments placeholder command executed.')
        ->assertSuccessful();
});
