<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mateffy\JobProgress\LaravelJobProgressServiceProvider;
use Mateffy\JobProgress\Traits\Progress;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateVersionedDocumentWithProgress as TrackedVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

require_once __DIR__.'/../Fixtures/VersionedIntegration.php';

beforeEach(function () {
    if (! trait_exists(Progress::class) || ! class_exists(VersionedStorage::class)
        || ! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! extension_loaded('sockets')) {
        $this->markTestSkipped('Install both optional packages and their Unix extensions to run the versioned integration tests.');
    }

    $this->app->register(LaravelJobProgressServiceProvider::class);
    $this->app->register(VersionedStorageServiceProvider::class);
    $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    $this->app->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
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
        $firstSnapshot = new RenderedDocument('first', $target, generator: FakeDocumentGenerator::class);
        $secondSnapshot = new RenderedDocument('second', $target, generator: FakeDocumentGenerator::class);
        $first = (new TrackedVersionedDocument($firstSnapshot));
        $second = (new TrackedVersionedDocument($secondSnapshot));
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

it('rejects synchronous database-backed batches with real versioned storage', function () {
    withRealVersionedStorage(function () {
        Config::set('queue.default', 'sync');
        $storage = new VersionedDocumentStorage;
        $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $job = (new TrackedVersionedDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)));

        expect(fn () => Bus::batch([$job])->dispatch())
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction.');

        expect($storage->versions($context))->toBe([]);
    });
});
