<?php

declare(strict_types=1);

use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\InvoiceDocument;

beforeEach(function () {
    View::addLocation(__DIR__.'/../Fixtures/views');
    Storage::fake('documents');
});

it('captures the rendered view and target in a render', function () {
    $rendered = (new InvoiceDocument(42))->render();

    expect($rendered->html)->toContain('<h1>Invoice 42</h1>')
        ->and($rendered->target->context)->toEqual(new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents'))
        ->and($rendered->target->storage)->toBe('disk')
        ->and($rendered->driver)->toBe('default');
});

it('requires a view to render', function () {
    $document = new class extends Document
    {
        public function path(): string
        {
            return 'missing.pdf';
        }
    };

    $document->html();
})->throws(RuntimeException::class, '$view can not be null!');

it('requires a path to be configured or overridden', function () {
    $document = new class extends Document {};

    $document->path();
})->throws(LogicException::class, 'The document path must be configured or overridden.');

it('uses the disk storage by default', function () {
    expect((new InvoiceDocument(1))->storage())->toBe('disk')
        ->and(app(DocumentStorageManager::class)->resolve('disk'))->toBeInstanceOf(DiskDocumentStorage::class);
});

it('reads the stored pdf file without rendering the view', function () {
    $document = new InvoiceDocument(7);

    expect($document->exists())->toBeFalse();

    app(DocumentStorageManager::class)->resolve($document->storage())->write($document->storageContext(), 'PDF');

    InvoiceDocument::$view = null;

    try {
        expect($document->exists())->toBeTrue()
            ->and($document->get())->toBe('PDF')
            ->and($document->versions())->toBe([]);
    } finally {
        InvoiceDocument::$view = 'invoice';
    }
});

it('detects changes against the last stored render', function () {
    $document = new InvoiceDocument(3);
    $rendered = $document->render();

    expect(app(DocumentSnapshotCache::class)->changed($rendered))->toBeTrue();

    $generated = app(FakeDocumentGenerator::class)->generate($rendered);
    app(DocumentPublisher::class)->publish($generated);

    $changed = new class(3) extends InvoiceDocument
    {
        public function data(): array
        {
            return ['number' => 'changed'];
        }
    };

    expect(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($changed->render()))->toBeTrue();
});

it('fingerprints the snapshot and its configuration identifiers', function () {
    $rendered = (new InvoiceDocument(8))->render();
    $differentDriver = new RenderedDocument($rendered->html, $rendered->target, 'other');

    expect($differentDriver->fingerprint())->not->toBe($rendered->fingerprint())
        ->and($rendered->fingerprint())
        ->toBe((new InvoiceDocument(8))->render()->fingerprint());
});

it('can serialize a queued job with the snapshot and storage identifier', function () {
    $rendered = new RenderedDocument('<h1>Invoice 9</h1>', new DocumentTarget(
        storage: 'disk',
        context: new FilesystemDocumentStorageContext('invoices/9.pdf', 'documents'),
    ));
    $job = new GenerateUniqueDocument($rendered);
    $restored = unserialize(serialize($job));
    $restoredSnapshot = (new ReflectionClass($restored))->getProperty('rendered')->getValue($restored);

    expect($restored)->toBeInstanceOf(GenerateUniqueDocument::class)
        ->and($restored->uniqueId())->toBe('documents:invoices/9.pdf')
        ->and($restoredSnapshot)->toEqual($rendered)
        ->and($restoredSnapshot->target->storage)->toBe('disk');
});

it('can serialize the selected generator class on a queued job', function () {
    $rendered = (new InvoiceDocument(14))->render();
    $job = app(DocumentJobFactory::class)->make($rendered)->withGenerator(FakeDocumentGenerator::class);
    $restored = unserialize(serialize($job));

    $rendererMethod = new ReflectionMethod($restored, 'generator');

    expect($rendererMethod->invoke($restored))->toBeInstanceOf(FakeDocumentGenerator::class);
});

it('resolves the default disk in the document target', function () {
    Config::set('filesystems.default', 'documents');

    $defaultDiskDocument = new class(12) extends InvoiceDocument
    {
        public static ?string $disk = null;
    };

    expect($defaultDiskDocument->target()->context->disk)->toBe('documents')
        ->and(app(DocumentJobFactory::class)->make($defaultDiskDocument->render())->uniqueId())
        ->toBe(app(DocumentJobFactory::class)->make((new InvoiceDocument(12))->render())->uniqueId());
});

it('does not use model serialization on document jobs', function (string $job) {
    expect(class_uses_recursive($job))->not->toHaveKey(SerializesModels::class);
})->with([
    GenerateUniqueDocument::class,
    GenerateVersionedDocument::class,
]);

it('deduplicates the same target across unique job subclasses', function () {
    Queue::fake();

    $trackedJob = new class((new InvoiceDocument(5))->render()) extends GenerateUniqueDocument {};

    dispatch(app(DocumentJobFactory::class)->make((new InvoiceDocument(5))->render()));
    dispatch($trackedJob);

    Queue::assertPushed(GenerateUniqueDocument::class, 1);
    Queue::assertPushed($trackedJob::class, 0);
});

it('preserves dispatching and queue interactions on document jobs', function (string $job) {
    Queue::fake();
    $rendered = (new InvoiceDocument(11))->render();

    $job::dispatch($rendered)->onConnection('redis')->onQueue('documents');

    Queue::assertPushed($job, fn ($queuedJob) => $queuedJob->connection === 'redis'
        && $queuedJob->queue === 'documents');

    $queuedJob = (new $job($rendered))->withFakeQueueInteractions();
    $queuedJob->release(15);
    $queuedJob->assertReleased(15);
})->with([
    GenerateUniqueDocument::class,
    GenerateVersionedDocument::class,
]);

it('resolves a container-bound generator when the queued job runs', function () {
    $rendered = (new InvoiceDocument(13))->render();
    app()->bind(DocumentGenerator::class, FakeDocumentGenerator::class);
    $job = app(DocumentJobFactory::class)->make($rendered);

    unserialize(serialize($job))->handle();

    expect(Storage::disk('documents')->get('invoices/13.pdf'))->toBe('rendered PDF');
});

it('rejects classes that are not document generators', function () {
    $job = new GenerateUniqueDocument((new InvoiceDocument(15))->render());

    $job->withGenerator(stdClass::class);
})->throws(InvalidArgumentException::class, 'The generator must extend '.DocumentGenerator::class.'.');

it('lets a custom job be queued for any storage', function () {
    Queue::fake();

    dispatch(app(DocumentJobFactory::class)->make((new InvoiceDocument(10))->render(), job: GenerateVersionedDocument::class));

    Queue::assertPushed(GenerateVersionedDocument::class);
});

it('queues the document once per target path', function () {
    Queue::fake();

    dispatch(app(DocumentJobFactory::class)->make((new InvoiceDocument(5))->render()));
    dispatch(app(DocumentJobFactory::class)->make((new InvoiceDocument(5))->render()));
    dispatch(app(DocumentJobFactory::class)->make((new InvoiceDocument(6))->render()));

    Queue::assertPushed(GenerateUniqueDocument::class, 2);
});

it('deduplicates filesystem paths normalized to the same target', function () {
    Queue::fake();
    $first = new FilesystemDocumentStorageContext('invoices//2026/../42.pdf', 'documents');
    $second = new FilesystemDocumentStorageContext('/invoices/42.pdf', 'documents');
    $firstJob = new GenerateUniqueDocument(new RenderedDocument('HTML', new DocumentTarget('disk', $first)));
    $secondJob = new GenerateUniqueDocument(new RenderedDocument('HTML', new DocumentTarget('disk', $second)));

    dispatch($firstJob);
    dispatch($secondJob);

    expect($first->path)->toBe('invoices/42.pdf')
        ->and($first->identity())->toBe($second->identity())
        ->and($firstJob->uniqueId())->toBe($secondJob->uniqueId());
    Queue::assertPushed(GenerateUniqueDocument::class, 1);
});

it('rejects a null default cache for unique job locks', function () {
    Config::set('cache.stores.no-locks', ['driver' => 'null']);
    Config::set('cache.default', 'no-locks');
    $job = new GenerateUniqueDocument((new InvoiceDocument(52))->render());

    expect(fn () => $job->uniqueVia())
        ->toThrow(LogicException::class, 'Unique document jobs require a default cache store with atomic locks.');
});
