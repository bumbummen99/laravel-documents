<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mateffy\JobProgress\Data\JobState;
use Mateffy\JobProgress\Data\JobStatus;
use Mateffy\JobProgress\JobProgressConfig;
use Mateffy\JobProgress\LaravelJobProgressServiceProvider;
use Mateffy\JobProgress\Traits\Progress;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateUniqueDocumentWithProgress;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateVersionedDocumentWithProgress as TrackedVersionedDocument;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingJobProgressConfig;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeVersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\IndependentTrackedDocument;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\InvoiceDocument;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueueTables;

/** @param class-string<DocumentGenerator> $generator */
function trackedVersionedExample(int $number, string $generator = FakeDocumentGenerator::class): TrackedVersionedDocument
{
    return (new TrackedVersionedDocument(new RenderedDocument(
        'HTML',
        new DocumentTarget('fake-versioned', new FilesystemDocumentStorageContext("invoices/{$number}.pdf", 'documents')),
        generator: $generator,
    )))->withGenerator($generator);
}

function useDatabaseQueueForProgressTests(): void
{
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    Config::set('queue.failed.driver', 'null');
    QueueTables::createJobs();
}

beforeEach(function () {
    if (! trait_exists(Progress::class)) {
        $this->markTestSkipped('Install mateffy/laravel-job-progress to run the optional integration tests.');
    }

    $this->app->register(LaravelJobProgressServiceProvider::class);
    app(DocumentStorageManager::class)->register('fake-versioned', fn () => new FakeVersionedDocumentStorage, TrackedVersionedDocument::class);
    Config::set('job-progress.cache.store', 'array');
    Config::set('queue.default', 'sync');
    Config::set('queue.batching.database', 'testing');
    Storage::fake('documents');

    QueueTables::createBatches();
});

it('tracks each document separately while Laravel tracks the batch', function () {
    Event::fake([DocumentStored::class]);
    $first = trackedVersionedExample(1);
    $second = trackedVersionedExample(2);
    $firstId = $first->generationId();
    $secondId = $second->generationId();

    expect(TrackedVersionedDocument::getProgress($firstId))->toBeNull();

    $batch = Bus::batch([$first, $second])->dispatch();
    $firstProgress = TrackedVersionedDocument::getProgress($firstId);
    $secondProgress = TrackedVersionedDocument::getProgress($secondId);

    expect($firstId)->not->toBe($secondId)
        ->and(class_uses_recursive($first))->not->toHaveKey(SerializesModels::class)
        ->and(Bus::findBatch($batch->id)->progress())->toBe(100)
        ->and($firstProgress?->status->isCompleted())->toBeTrue()
        ->and($secondProgress?->status->isCompleted())->toBeTrue()
        ->and($firstProgress?->result)->toBeString()->not->toBeEmpty()
        ->and($secondProgress?->result)->toBeString()->not->toBeEmpty();

    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $firstId
        && $event->version === $firstProgress->result
        && app(DocumentStorageManager::class)->resolve($event->target->storage)->get($event->target->context, $event->version) === 'rendered PDF');
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $secondId
        && $event->version === $secondProgress->result
        && app(DocumentStorageManager::class)->resolve($event->target->storage)->get($event->target->context, $event->version) === 'rendered PDF');
});

it('tracks an unversioned unique job without requiring a batch', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/unique.pdf', 'documents'));
    $job = (new GenerateUniqueDocumentWithProgress(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);
    $id = $job->generationId();

    dispatch_sync($job);

    $progress = GenerateUniqueDocumentWithProgress::getProgress($id);

    expect($progress?->status->isCompleted())->toBeTrue()
        ->and($progress?->result)->toBeNull()
        ->and(Storage::disk('documents')->get('invoices/unique.pdf'))->toBe('rendered PDF')
        ->and(class_uses_recursive($job))->not->toHaveKey(SerializesModels::class);
});

it('allows contract-only custom jobs to reuse the progress integration', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/independent.pdf', 'documents'));
    $job = (new IndependentTrackedDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    $job->handle();

    expect($job)->toBeInstanceOf(GenerateDocument::class)
        ->and(IndependentTrackedDocument::getProgress($job->generationId())?->status->isCompleted())->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/independent.pdf'))->toBe('rendered PDF');
});

it('shares unique locks between tracked and untracked jobs', function () {
    Queue::fake();
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/unique.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    dispatch(new GenerateUniqueDocument($rendered));
    dispatch(new GenerateUniqueDocumentWithProgress($rendered));

    Queue::assertPushed(GenerateUniqueDocument::class, 1);
    Queue::assertPushed(GenerateUniqueDocumentWithProgress::class, 0);
});

it('honours individual cancellation for a tracked unique job', function () {
    Event::fake([DocumentRendering::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/cancelled.pdf', 'documents'));
    $job = (new GenerateUniqueDocumentWithProgress(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);
    GenerateUniqueDocumentWithProgress::getProgress($job->generationId(), createIfMissing: true)->cancel();

    dispatch_sync($job);

    expect(GenerateUniqueDocumentWithProgress::getProgress($job->generationId())?->status->isCancelled())->toBeTrue()
        ->and(Storage::disk('documents')->exists('invoices/cancelled.pdf'))->toBeFalse();
    Event::assertNotDispatched(DocumentRendering::class);
});

it('allows a named storage registration to select a built-in tracked job', function () {
    app(DocumentStorageManager::class)->register(
        'tracked-disk',
        fn () => app(DiskDocumentStorage::class),
        GenerateUniqueDocumentWithProgress::class,
    );
    $document = new class(51) extends InvoiceDocument
    {
        public static string $storage = 'tracked-disk';

        public function html(): string
        {
            return 'HTML';
        }
    };

    $job = app(DocumentJobFactory::class)->make($document->render(), job: $document->job());

    expect($job)->toBeInstanceOf(GenerateUniqueDocumentWithProgress::class)
        ->and(fn () => $job->withBatchId('batch-id'))
        ->toThrow(LogicException::class, 'Unique document jobs cannot be batched.');
});

it('propagates a tracked unique job progress failure after storage', function () {
    $this->app->bind(JobProgressConfig::class, fn ($app, array $parameters) => new FailingJobProgressConfig(
        [JobStatus::Completed],
        'Unique progress cache failed after storage',
        $parameters['cancel_threshold'] ?? 0.0,
    ));
    Exceptions::fake();
    Event::fake([DocumentStored::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/unique-failure.pdf', 'documents'));
    $job = (new GenerateUniqueDocumentWithProgress(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    expect(fn () => dispatch_sync($job))
        ->toThrow(RuntimeException::class, 'Unique progress cache failed after storage');

    expect(Storage::disk('documents')->get('invoices/unique-failure.pdf'))->toBe('rendered PDF');
    Event::assertDispatchedOnce(DocumentStored::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Unique progress cache failed after storage');
});

it('preserves upstream handling of a secondary progress failure after storage', function () {
    $this->app->bind(JobProgressConfig::class, fn ($app, array $parameters) => new class([JobStatus::Completed], 'Completion reporting failed.', $parameters['cancel_threshold'] ?? 0.0) extends FailingJobProgressConfig
    {
        public function saveJobProgress(JobState $progress): void
        {
            if ($progress->status === JobStatus::Failed) {
                throw new RuntimeException('Failure reporting also failed.');
            }

            parent::saveJobProgress($progress);
        }
    });
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/report-failure.pdf', 'documents'));
    $job = (new GenerateUniqueDocumentWithProgress(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, 'Failure reporting also failed.');

    expect($job->generated())->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/report-failure.pdf'))->toBe('rendered PDF');
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Completion reporting failed.');
});

it('marks a cancelled batch job as cancelled without rendering', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class]);
    $job = trackedVersionedExample(3);
    $id = $job->generationId();

    $batch = Bus::batch([$job])->before(fn (Batch $batch) => $batch->cancel())->dispatch();
    $progress = TrackedVersionedDocument::getProgress($id);

    expect(Bus::findBatch($batch->id)->cancelled())->toBeTrue()
        ->and(Bus::findBatch($batch->id)->progress())->toBe(100)
        ->and($progress?->status->isCancelled())->toBeTrue()
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Event::assertNotDispatched(DocumentRendering::class);
    Event::assertNotDispatched(DocumentStored::class);
});

it('tracks an asynchronously queued batch job', function (bool $cancelled) {
    useDatabaseQueueForProgressTests();

    $job = trackedVersionedExample(9);
    $id = $job->generationId();
    $batch = Bus::batch([$job])->dispatch();

    expect(TrackedVersionedDocument::getProgress($id))->toBeNull()
        ->and(Bus::findBatch($batch->id)->progress())->toBe(0);

    if ($cancelled) {
        $batch->cancel();
    }

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])
        ->assertExitCode(0);

    $progress = TrackedVersionedDocument::getProgress($id);
    expect(Bus::findBatch($batch->id)->progress())->toBe(100)
        ->and($progress?->status->isCancelled())->toBe($cancelled)
        ->and($progress?->status->isCompleted())->toBe(! $cancelled)
        ->and(Storage::disk('documents')->allFiles('invoices/9.pdf'))->toHaveCount($cancelled ? 0 : 1);
})->with([
    'completed' => [false],
    'cancelled' => [true],
]);

it('records batch failures separately from the processed-job percentage', function () {
    useDatabaseQueueForProgressTests();
    Exceptions::fake();
    $job = trackedVersionedExample(10, FailingDocumentGenerator::class);
    $id = $job->generationId();
    $batch = Bus::batch([$job])->allowFailures()->dispatch();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])
        ->assertExitCode(0);

    $batch = Bus::findBatch($batch->id);

    expect($batch->failedJobs)->toBe(1)
        ->and($batch->progress())->toBe(0)
        ->and($batch->cancelled())->toBeFalse()
        ->and(TrackedVersionedDocument::getProgress($id)?->status->isFailed())->toBeTrue();
});

it('keeps an individually cancelled job distinct from its Laravel batch', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class]);
    $job = trackedVersionedExample(7);
    $id = $job->generationId();
    TrackedVersionedDocument::getProgress($id, createIfMissing: true)->cancel();

    $batch = Bus::batch([$job])->dispatch();

    expect(Bus::findBatch($batch->id)->cancelled())->toBeFalse()
        ->and(Bus::findBatch($batch->id)->progress())->toBe(100)
        ->and(TrackedVersionedDocument::getProgress($id)?->status->isCancelled())->toBeTrue();
    Event::assertNotDispatched(DocumentRendering::class);
    Event::assertNotDispatched(DocumentStored::class);
});

it('rethrows rendering errors and records the failed progress', function () {
    Event::fake([DocumentRenderFailed::class, DocumentStored::class]);
    Exceptions::fake();
    $job = trackedVersionedExample(4, FailingDocumentGenerator::class);
    $id = $job->generationId();

    expect(fn () => dispatch_sync($job))->toThrow(RuntimeException::class, 'PDF generation failed')
        ->and(TrackedVersionedDocument::getProgress($id)?->status->isFailed())->toBeTrue();
    Event::assertDispatched(DocumentRenderFailed::class);
    Event::assertNotDispatched(DocumentStored::class);
});

it('fails the batch job when progress persistence throws after storage', function (bool $failErrorStatus) {
    useDatabaseQueueForProgressTests();

    $failureStatuses = $failErrorStatus
        ? [JobStatus::Completed, JobStatus::Failed]
        : [JobStatus::Completed];
    $this->app->bind(JobProgressConfig::class, fn ($app, array $parameters) => new FailingJobProgressConfig(
        $failureStatuses,
        'Progress cache failed after storage',
        $parameters['cancel_threshold'] ?? 0.0,
    ));

    Exceptions::fake();
    Event::fake([DocumentStored::class]);
    $job = trackedVersionedExample(5);
    $id = $job->generationId();

    $batch = Bus::batch([$job])->dispatch();

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])
        ->assertExitCode(0);

    expect(Bus::findBatch($batch->id)->failedJobs)->toBe(1)
        ->and(Bus::findBatch($batch->id)->progress())->toBe(0)
        ->and(Storage::disk('documents')->allFiles('invoices/5.pdf'))->toHaveCount(1);

    if (! $failErrorStatus) {
        expect(TrackedVersionedDocument::getProgress($id)?->status->isFailed())->toBeTrue();
    }

    Event::assertDispatchedOnce(DocumentStored::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Progress cache failed after storage');
})->with([
    'failure can be recorded' => [false],
    'failure cannot be recorded' => [true],
]);

it('rethrows a progress failure before rendering', function () {
    $this->app->bind(JobProgressConfig::class, fn ($app, array $parameters) => new FailingJobProgressConfig(
        null,
        'Progress cache failed before rendering',
        $parameters['cancel_threshold'] ?? 0.0,
    ));

    Exceptions::fake();
    Event::fake([DocumentRendering::class]);

    expect(fn () => dispatch_sync(trackedVersionedExample(8)))
        ->toThrow(RuntimeException::class, 'Progress cache failed before rendering')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Event::assertNotDispatched(DocumentRendering::class);
});

it('does not suppress a later rendering failure when a job instance is reused', function () {
    Exceptions::fake();
    $job = trackedVersionedExample(6);
    $job->handle();
    app()->bind(FakeDocumentGenerator::class, FailingDocumentGenerator::class);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'PDF generation failed');
});
