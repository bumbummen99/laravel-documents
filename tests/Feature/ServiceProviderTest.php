<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\LaravelDocumentsServiceProvider;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;

it('merges the package configuration', function () {
    expect(config('laravel-documents'))->toBe([
        'snapshot_cache' => [
            'store' => null,
            'lock_seconds' => 300,
            'lock_wait_seconds' => 30,
        ],
    ]);
});

it('preserves application configuration when registering the package', function () {
    $snapshotCache = [
        'store' => 'file',
        'lock_seconds' => 120,
        'lock_wait_seconds' => 10,
    ];

    config(['laravel-documents.snapshot_cache' => $snapshotCache]);
    (new LaravelDocumentsServiceProvider(app()))->register();

    expect(config('laravel-documents.snapshot_cache'))->toBe($snapshotCache);
});

it('registers shared document services', function () {
    foreach ([DocumentSnapshotCache::class, DocumentPdfDriverManager::class, DocumentStorageManager::class] as $service) {
        expect(app($service))->toBe(app($service));
    }
});

it('resolves independent document operation services through the container', function () {
    expect(app(DocumentGenerator::class))->toBeInstanceOf(DocumentGenerator::class)
        ->and(app(DocumentPublisher::class))->toBeInstanceOf(DocumentPublisher::class)
        ->and(app(DocumentJobFactory::class))->toBeInstanceOf(DocumentJobFactory::class)
        ->and(app(DocumentWorkflow::class))->toBeInstanceOf(DocumentWorkflow::class)
        ->and(Documents::getFacadeRoot())->toBeInstanceOf(DocumentWorkflow::class);
});

it('registers the storage-specific default jobs', function () {
    $manager = app(DocumentStorageManager::class);

    expect($manager->job('disk'))->toBe(GenerateUniqueDocument::class)
        ->and($manager->job('versioned'))->toBe(GenerateVersionedDocument::class);
});

it('publishes only the package configuration', function () {
    $paths = ServiceProvider::pathsToPublish(LaravelDocumentsServiceProvider::class, 'laravel-documents');

    expect($paths)->toHaveCount(1)
        ->and(ServiceProvider::pathsToPublish(LaravelDocumentsServiceProvider::class, 'laravel-documents-config'))->toBe($paths)
        ->and(array_map('realpath', array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/laravel-documents.php')])
        ->and(array_values($paths))->toBe([config_path('laravel-documents.php')]);
});

it('does not register a placeholder command', function () {
    expect(Artisan::all())->not->toHaveKey('laravel-documents:placeholder');
});
