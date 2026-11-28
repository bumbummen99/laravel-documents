<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\Jobs\UniqueDocumentCacheRepository;
use SkyRaptor\LaravelDocuments\LaravelDocumentsServiceProvider;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;

it('merges the package configuration', function () {
    expect(config('laravel-documents'))->toBe([
        'latest_snapshot_wins' => false,
        'coalescing' => [
            'enabled' => false,
            'connection' => null,
            'lease_seconds' => 300,
            'stage_retention_seconds' => 3600,
        ],
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

    config([
        'laravel-documents.snapshot_cache' => $snapshotCache,
        'laravel-documents.latest_snapshot_wins' => true,
    ]);
    (new LaravelDocumentsServiceProvider(app()))->register();

    expect(config('laravel-documents.snapshot_cache'))->toBe($snapshotCache)
        ->and(config('laravel-documents.latest_snapshot_wins'))->toBeTrue();
});

it('preserves defaults for partial snapshot cache configuration and can publish', function (array $overrides) {
    config([
        'cache.default' => 'array',
        'laravel-documents.snapshot_cache' => $overrides,
    ]);
    (new LaravelDocumentsServiceProvider(app()))->register();

    expect(config('laravel-documents.snapshot_cache'))->toBe(array_replace([
        'store' => null,
        'lock_seconds' => 300,
        'lock_wait_seconds' => 30,
    ], $overrides));

    $rendered = new RenderedDocument('HTML', new DocumentTarget('records', new RecordStorageContext(42)));
    $cache = app(DocumentSnapshotCache::class);

    expect($cache->publish(new GeneratedDocument($rendered, 'PDF', 'generation-1'), fn () => 'version-1'))
        ->toBe('version-1')
        ->and($cache->changed($rendered))->toBeFalse();
})->with([
    'store only' => [['store' => 'array']],
    'lease only' => [['lock_seconds' => 120]],
    'wait only' => [['lock_wait_seconds' => 10]],
    'empty section' => [[]],
]);

it('registers shared document services', function () {
    foreach ([DocumentSnapshotCache::class, DocumentPdfDriverManager::class, DocumentStorageManager::class] as $service) {
        expect(app($service))->toBe(app($service));
    }
});

it('rejects selecting the internal lock cache as the default store', function () {
    config(['cache.default' => UniqueDocumentCacheRepository::STORE_NAME]);
    $job = new GenerateUniqueDocument(new RenderedDocument('HTML', new DocumentTarget('disk', new RecordStorageContext(42))));

    expect(fn () => $job->uniqueVia())
        ->toThrow(LogicException::class, 'The internal document lock cache cannot be the default cache store.');
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

it('publishes the coalescing tables migration', function () {
    $paths = ServiceProvider::pathsToPublish(LaravelDocumentsServiceProvider::class, 'laravel-documents-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_map('realpath', array_keys($paths)))->toBe([realpath(__DIR__.'/../../database/migrations/2026_11_27_105147_create_document_coalescing_tables.php')])
        ->and(array_values($paths))->toBe([database_path('migrations/2026_11_27_105147_create_document_coalescing_tables.php')]);
});

it('does not register a placeholder command', function () {
    expect(Artisan::all())->not->toHaveKey('laravel-documents:placeholder');
});
