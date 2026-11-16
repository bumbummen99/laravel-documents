<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mateffy\JobProgress\LaravelJobProgressServiceProvider;
use Mateffy\JobProgress\Traits\Progress;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateVersionedDocumentWithProgress as TrackedVersionedDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueueTables;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

function withRealVersionedStorage(Closure $test): void
{
    $database = tempnam(sys_get_temp_dir(), 'documents-batch-');

    if ($database === false) {
        throw new RuntimeException('Unable to create the versioned storage test database.');
    }

    try {
        Config::set('database.connections.documents-integration', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'documents-integration');
        Config::set('laravel-versioned-storage.connection', 'documents-integration');
        Config::set('queue.batching.database', 'documents-integration');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'documents-integration',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        Config::set('queue.failed.driver', 'null');

        $packageRoot = dirname((new ReflectionClass(VersionedStorage::class))->getFileName(), 2);
        $migrations = glob($packageRoot.'/database/migrations/*.php');

        if ($migrations === false || $migrations === []) {
            throw new RuntimeException('Versioned storage migrations could not be found.');
        }

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        QueueTables::createBatches();
        QueueTables::createJobs();

        $test();
    } finally {
        DB::disconnect('documents-integration');
        unlink($database);
    }
}

beforeEach(function () {
    if (! trait_exists(Progress::class) || ! class_exists(VersionedStorage::class)
        || ! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! extension_loaded('sockets')) {
        $this->markTestSkipped('Install both optional packages and their Unix extensions to run the versioned integration tests.');
    }

    $this->app->register(LaravelJobProgressServiceProvider::class);
    $this->app->register(VersionedStorageServiceProvider::class);
    $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    Config::set('job-progress.cache.store', 'array');
    Storage::fake('documents');
});

it('tracks queued batch jobs while storing real document versions', function () {
    withRealVersionedStorage(function () {
        Event::fake([DocumentStored::class]);
        $failure = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failure): void {
            $failure = $event->exception;
        });
        Config::set('queue.default', 'database');
        $storage = new VersionedDocumentStorage;
        $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $firstSnapshot = new RenderedDocument('first', $target);
        $secondSnapshot = new RenderedDocument('second', $target);
        $first = (new TrackedVersionedDocument($firstSnapshot))->withGenerator(FakeDocumentGenerator::class);
        $second = (new TrackedVersionedDocument($secondSnapshot))->withGenerator(FakeDocumentGenerator::class);
        $firstId = $first->generationId();
        $secondId = $second->generationId();

        $batch = Bus::batch([$first, $second])->dispatch();

        expect(Bus::findBatch($batch->id)->progress())->toBe(0);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        if ($failure !== null) {
            throw $failure;
        }

        expect(Bus::findBatch($batch->id)->failedJobs)->toBe(0)
            ->and(Bus::findBatch($batch->id)->progress())->toBe(50)
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeFalse()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeTrue();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        if ($failure !== null) {
            throw $failure;
        }

        $firstProgress = TrackedVersionedDocument::getProgress($firstId);
        $secondProgress = TrackedVersionedDocument::getProgress($secondId);
        $versions = $storage->versions($context);

        expect(Bus::findBatch($batch->id)->progress())->toBe(100)
            ->and(Bus::findBatch($batch->id)->failedJobs)->toBe(0)
            ->and($firstProgress?->status->isCompleted())->toBeTrue()
            ->and($secondProgress?->status->isCompleted())->toBeTrue()
            ->and($firstProgress?->result)->toBeString()->not->toBeEmpty()
            ->and($secondProgress?->result)->toBeString()->not->toBeEmpty()
            ->and($firstProgress->result)->not->toBe($secondProgress->result)
            ->and($versions)->toBe([$firstProgress->result, $secondProgress->result])
            ->and($storage->get($context, $versions[0]))->toBe('rendered PDF')
            ->and($storage->get($context, $versions[1]))->toBe('rendered PDF')
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeTrue()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeFalse();

        Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $firstId
            && $event->version === $firstProgress->result);
        Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $secondId
            && $event->version === $secondProgress->result);
    });
});

it('makes the last published version current regardless of job creation order', function () {
    withRealVersionedStorage(function () {
        $context = new FilesystemDocumentStorageContext('invoices/order.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $firstSnapshot = new RenderedDocument('first-created', $target);
        $secondSnapshot = new RenderedDocument('second-created', $target);
        $first = (new GenerateVersionedDocument($firstSnapshot))->withGenerator(HtmlDocumentGenerator::class);
        $second = (new GenerateVersionedDocument($secondSnapshot))->withGenerator(HtmlDocumentGenerator::class);

        $second->handle();
        $first->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$second->version(), $first->version()])
            ->and($storage->get($context))->toBe('first-created')
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeFalse()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeTrue();
    });
});

it('keeps older version directories and their legacy hash files readable', function () {
    withRealVersionedStorage(function () {
        $context = new FilesystemDocumentStorageContext('invoices/legacy.pdf', 'documents');
        $legacy = VersionedStorage::directory($context->path, disk: $context->disk)->write(
            function ($files) {
                $files->put('document.pdf', 'old PDF');
                $files->put('document.hash', 'old hash');
            },
        );
        $target = new DocumentTarget('versioned', $context);
        $job = (new GenerateVersionedDocument(new RenderedDocument('new PDF', $target)))->withGenerator(HtmlDocumentGenerator::class);
        $job->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$legacy, $job->version()])
            ->and($storage->get($context, $legacy))->toBe('old PDF')
            ->and($storage->get($context))->toBe('new PDF')
            ->and(VersionedStorage::directory($context->path, disk: $context->disk)->get('document.hash', $legacy))->toBe('old hash');
    });
});

it('rejects synchronous database-backed batches with real versioned storage', function () {
    withRealVersionedStorage(function () {
        Config::set('queue.default', 'sync');
        $storage = new VersionedDocumentStorage;
        $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $job = (new TrackedVersionedDocument(new RenderedDocument('HTML', $target)))->withGenerator(FakeDocumentGenerator::class);

        expect(fn () => Bus::batch([$job])->dispatch())
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction.');

        expect($storage->versions($context))->toBe([]);
    });
});
