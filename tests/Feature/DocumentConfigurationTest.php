<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\ConfiguredPdfDriver;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;

it('rejects an empty PDF configuration name', function () {
    expect(fn () => app(DocumentPdfDriverManager::class)->register('', fn () => new ConfiguredPdfDriver('test')))
        ->toThrow(InvalidArgumentException::class, 'A document PDF driver configuration needs a name.');
});

it('defines complete zero-margin PDF defaults', function () {
    app()->instance(PdfDriver::class, new ConfiguredPdfDriver('test'));

    expect(app(DocumentPdfDriverManager::class)->builder('default')->margins)
        ->toBe(['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0, 'unit' => 'mm']);
});

it('rejects view rendering during PDF generation', function (string $method) {
    $manager = app(DocumentPdfDriverManager::class);
    $manager->register(
        'view',
        fn () => new ConfiguredPdfDriver('test'),
        fn (PdfBuilder $builder) => $builder->{$method}('invoice'),
    );

    expect(fn () => $manager->builder('view'))
        ->toThrow(InvalidArgumentException::class, 'Document PDF builders must use rendered HTML, not views.');
})->with(['view', 'headerView', 'footerView']);

it('creates an independent configured PDF builder for each generation', function () {
    $manager = app(DocumentPdfDriverManager::class);
    $manager->register(
        'configured',
        fn () => new ConfiguredPdfDriver('test'),
        fn (PdfBuilder $builder) => $builder->format('A4')->meta(title: 'Invoice'),
    );
    $first = $manager->builder('configured');
    $first->format('Letter')->meta(title: 'Modified');
    $second = $manager->builder('configured');

    expect($second)->not->toBe($first)
        ->and($second->format)->toBe('A4')
        ->and($second->metadata?->title)->toBe('Invoice');
});

it('rejects invalid storage registrations', function (string $name, string $job) {
    expect(fn () => app(DocumentStorageManager::class)->register($name, fn () => new DiskDocumentStorage, $job))
        ->toThrow(InvalidArgumentException::class, 'A storage configuration needs a name and a document generation job.');
})->with([
    'empty name' => ['', GenerateUniqueDocument::class],
    'invalid job' => ['custom', stdClass::class],
]);

it('uses an inherited configured document path', function () {
    $document = new class extends Document
    {
        public static ?string $path = 'invoices/configured.pdf';
    };

    expect($document->path())->toBe('invoices/configured.pdf');
});

it('rejects a missing configured document path', function (?string $path) {
    $document = new class extends Document {};
    $document::$path = $path;

    expect(fn () => $document->path())
        ->toThrow(LogicException::class, 'The document path must be configured or overridden.');
})->with([null, '']);

it('forwards versioned reads without rendering the document', function () {
    $context = new RecordStorageContext(42);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('get')->once()->withArgs(fn ($target, $version) => $target == $context && $version === 'version-1')->andReturn('PDF');
    $storage->shouldReceive('exists')->once()->withArgs(fn ($target, $version) => $target == $context && $version === 'version-1')->andReturn(true);
    $storage->shouldReceive('versions')->once()->withArgs(fn ($target) => $target == $context)->andReturn(['version-1', 'version-2']);
    app(DocumentStorageManager::class)->register('records', fn () => $storage);
    $document = new class extends Document
    {
        public static string $storage = 'records';

        public function storageContext(): DocumentStorageContext
        {
            return new RecordStorageContext(42);
        }

        public function html(): string
        {
            throw new LogicException('Stored reads must not render HTML.');
        }
    };

    expect($document->get('version-1'))->toBe('PDF')
        ->and($document->exists('version-1'))->toBeTrue()
        ->and($document->versions())->toBe(['version-1', 'version-2']);
});

it('explains the missing optional versioned storage dependency', function () {
    expect(fn () => app(DocumentStorageManager::class)->resolve('versioned'))
        ->toThrow(LogicException::class, VersionedDocumentStorage::class.' requires the skyraptor/laravel-versioned-storage package.');
})->skip(fn () => class_exists(VersionedStorage::class), 'Requires an environment without optional versioned storage.');
