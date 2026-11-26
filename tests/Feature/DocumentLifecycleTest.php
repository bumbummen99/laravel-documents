<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfOptions;

beforeEach(function () {
    Storage::fake('documents');
});

it('renders and generates once before publishing the exact generated result', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $document = new class extends Document
    {
        public static string $storage = 'counted';

        public static string $pdfDriver = 'counted';

        public static ?string $disk = 'documents';

        public static ?string $path = 'invoice.pdf';

        public int $renders = 0;

        public string $body = 'original';

        public function html(): string
        {
            $this->renders++;

            return $this->body;
        }
    };
    $driver = Mockery::mock(PdfDriver::class);
    $driver->shouldReceive('generatePdf')->once()
        ->with('original', null, null, Mockery::type(PdfOptions::class))
        ->andReturn('final PDF bytes');
    app(DocumentPdfDriverManager::class)->register('counted', fn () => $driver);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()
        ->withArgs(fn ($context, $contents) => $context->path === 'invoice.pdf' && $contents === 'final PDF bytes')
        ->andReturn('version-1');
    app(DocumentStorageManager::class)->register('counted', fn () => $storage);

    $rendered = $document->render();
    Event::assertNotDispatched(DocumentRendering::class);
    $generated = Documents::generate($rendered, generationId: 'generation-123');
    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertNotDispatched(DocumentStored::class);
    $document->body = 'changed after generation';
    app(DocumentPdfDriverManager::class)->register('counted', fn () => throw new RuntimeException('Must not generate again.'));

    expect($rendered)->toBeInstanceOf(RenderedDocument::class)
        ->and($generated)->toBeInstanceOf(GeneratedDocument::class)
        ->and($generated->contents)->toBe('final PDF bytes')
        ->and(Documents::publish($generated))->toBe('version-1')
        ->and($document->renders)->toBe(1)
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();
    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'generation-123');
});

it('keeps the facade generate then publish sequence to one render and one generation', function () {
    $document = Mockery::mock(Document::class);
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $document->shouldReceive('render')->once()->andReturn($rendered);
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldReceive('generate')->once()->with($rendered, null)
        ->andReturn(new GeneratedDocument($rendered, 'one PDF', 'generation-1'));
    app()->instance(DocumentGenerator::class, $generator);

    $generated = Documents::generate($document);

    expect(Documents::publish($generated))->toBeNull()
        ->and(Storage::disk('documents')->get('invoice.pdf'))->toBe('one PDF');
});

it('publishes a serialized generation result without a generator or driver configuration', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ), 'not-registered');
    $generated = unserialize(serialize(new GeneratedDocument($rendered, 'already generated', 'generation-1')));
    app()->bind(DocumentGenerator::class, fn () => throw new RuntimeException('Must not resolve a generator.'));
    app()->bind(DocumentPdfDriverManager::class, fn () => throw new RuntimeException('Must not resolve PDF drivers.'));

    app(DocumentPublisher::class)->publish($generated);

    expect(Storage::disk('documents')->get('invoice.pdf'))->toBe('already generated')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();
});

it('publishes an existing generated result with an explicit outcome without generating again', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generated = new GeneratedDocument($rendered, 'already generated', 'generation-1');
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(DocumentGenerator::class, $generator);
    Event::fake([DocumentStored::class]);

    $result = Documents::publishWithResult($generated, generationId: 'generation-1');

    expect($result->status)->toBe(DocumentPublicationStatus::Published)
        ->and($result->version)->toBeNull()
        ->and(Storage::disk('documents')->get('invoice.pdf'))->toBe('already generated');
    Event::assertDispatchedOnce(DocumentStored::class);
    expect(fn () => Documents::publishWithResult($generated, generationId: 'different'))
        ->toThrow(InvalidArgumentException::class, 'it cannot be replaced');
});

it('reports low-level publication outcomes and propagates storage failures', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'failing',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generated = new GeneratedDocument($rendered, 'PDF', 'generation-1');
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andThrow(new RuntimeException('Storage unavailable.'));
    $storage->shouldReceive('write')->once()->andReturn('version-1');
    app(DocumentStorageManager::class)->register('failing', fn () => $storage);
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $publisher = app(DocumentPublisher::class);

    expect(fn () => $publisher->publishWithResult($generated))->toThrow(RuntimeException::class, 'Storage unavailable.');
    $result = $publisher->publishWithResult($generated);

    expect($result->status)->toBe(DocumentPublicationStatus::Published)
        ->and($result->version)->toBe('version-1');
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
    Event::assertDispatchedOnce(DocumentStored::class);
});

it('rejects publishing a merely rendered document through the publisher', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));

    expect(fn () => app(DocumentPublisher::class)->publish($rendered))->toThrow(TypeError::class)
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
});

it('retries publication with the same bytes and identity without repeating generation', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'retry',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ), 'counted');
    $driver = Mockery::mock(PdfDriver::class);
    $driver->shouldReceive('generatePdf')->once()->andReturn('final PDF');
    app(DocumentPdfDriverManager::class)->register('counted', fn () => $driver);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->with($rendered->target->context, 'final PDF')
        ->andThrow(new RuntimeException('Storage unavailable.'));
    $storage->shouldReceive('write')->once()->with($rendered->target->context, 'final PDF')->andReturn('version-1');
    app(DocumentStorageManager::class)->register('retry', fn () => $storage);
    $generated = Documents::generate($rendered, generationId: 'retry-generation');

    expect(fn () => Documents::publish($generated))->toThrow(RuntimeException::class, 'Storage unavailable.')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeTrue();
    expect(Documents::publish($generated))->toBe('version-1')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();

    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatched(DocumentRenderFailed::class, fn (DocumentRenderFailed $event) => $event->generationId === 'retry-generation');
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'retry-generation');
});

it('rejects replacing the identity of an existing generation result', function (string $generationId) {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generated = new GeneratedDocument($rendered, 'PDF', 'original-generation');

    expect(fn () => Documents::publish($generated, generationId: $generationId))
        ->toThrow(InvalidArgumentException::class, 'it cannot be replaced')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
})->with(['different-generation', '']);

it('accepts a matching explicit identity without regenerating', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generated = new GeneratedDocument($rendered, 'PDF', 'original-generation');

    Documents::publish($generated, generationId: 'original-generation');

    expect(Storage::disk('documents')->get('invoice.pdf'))->toBe('PDF');
});

it('creates jobs directly from rendered input without rendering or generating again', function (string $method, ?string $jobClass, string $expectedClass) {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(DocumentGenerator::class, $generator);

    $job = Documents::{$method}($rendered, connection: 'redis', queue: 'pdf', job: $jobClass);

    expect($job)->toBeInstanceOf($expectedClass)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('pdf');
})->with(['makeJob', 'makeJobIfChanged'])->with([
    'storage default' => [null, GenerateUniqueDocument::class],
    'explicit job' => [GenerateVersionedDocument::class, GenerateVersionedDocument::class],
]);
