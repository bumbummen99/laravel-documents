<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Jobs\AbstractGenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueuedDocumentEventListener;

beforeEach(function () {
    Storage::fake('documents');
    app()->bind(DocumentGenerator::class, FakeDocumentGenerator::class);
});

it('emits rendering and stored events around a synchronous save', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents'));
    $rendered = new RenderedDocument('<h1>Private invoice</h1>', $target);

    expect(app(DocumentWorkflow::class)->publish($rendered))->toBeNull();

    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);

    $rendering = Event::dispatched(DocumentRendering::class)->first()[0];
    $stored = Event::dispatched(DocumentStored::class)->first()[0];

    expect($rendering->target)->toBe($target)
        ->and($rendering->generationId)->not->toBeEmpty()
        ->and($stored->target)->toBe($target)
        ->and($stored->generationId)->toBe($rendering->generationId)
        ->and($stored->version)->toBeNull()
        ->and(Storage::disk('documents')->get('invoices/42.pdf'))->toBe('rendered PDF')
        ->and(serialize($stored))->not->toContain('<h1>Private invoice</h1>');
});

it('includes the stored version in the event', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andReturn('revision-123');
    app(DocumentStorageManager::class)->register('custom', fn () => $storage);
    $target = new DocumentTarget('custom', new FilesystemDocumentStorageContext('invoices/43.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    expect(app(DocumentWorkflow::class)->publish($rendered))->toBe('revision-123');

    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->target === $target
        && $event->version === 'revision-123');
});

it('dispatches the stored event only after the PDF is written', function () {
    $pdfWasStored = false;
    Event::listen(DocumentStored::class, function (DocumentStored $event) use (&$pdfWasStored) {
        $pdfWasStored = app(DocumentStorageManager::class)
            ->resolve($event->target->storage)
            ->get($event->target->context) === 'rendered PDF';
    });

    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/43.pdf', 'documents'));
    app(DocumentWorkflow::class)->publish(new RenderedDocument('HTML', $target));

    expect($pdfWasStored)->toBeTrue();
});

it('emits a failure event and rethrows a rendering failure', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/44.pdf', 'documents'));
    $rendered = new RenderedDocument('<h1>Private invoice</h1>', $target);
    app()->bind(DocumentGenerator::class, FailingDocumentGenerator::class);

    expect(fn () => app(DocumentWorkflow::class)->publish($rendered))->toThrow(RuntimeException::class, 'PDF generation failed');

    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);

    $rendering = Event::dispatched(DocumentRendering::class)->first()[0];
    $failed = Event::dispatched(DocumentRenderFailed::class)->first()[0];
    $restored = unserialize(serialize($failed));

    expect($failed->target)->toBe($target)
        ->and($failed->generationId)->toBe($rendering->generationId)
        ->and($failed->exceptionClass)->toBe(RuntimeException::class)
        ->and($failed->message)->toBe('PDF generation failed')
        ->and($restored)->toEqual($failed)
        ->and(serialize($failed))->not->toContain('<h1>Private invoice</h1>');
});

it('emits a failure event when the captured generator cannot be resolved', function () {
    Event::fake([DocumentRendering::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/resolution-failure.pdf', 'documents'));
    $snapshot = new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class);
    app()->bind(FakeDocumentGenerator::class, fn () => throw new RuntimeException('Generator resolution failed.'));

    expect(fn () => DocumentGenerator::generate($snapshot, 'resolution-id'))
        ->toThrow(RuntimeException::class, 'Generator resolution failed.');

    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertDispatched(DocumentRenderFailed::class, fn (DocumentRenderFailed $event) => $event->target === $target
        && $event->generationId === 'resolution-id'
        && $event->message === 'Generator resolution failed.');
});

it('emits a failure event and rethrows a storage failure', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andThrow(new RuntimeException('Storage failed'));
    app(DocumentStorageManager::class)->register('custom', fn () => $storage);
    $target = new DocumentTarget('custom', new FilesystemDocumentStorageContext('invoices/45.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    expect(fn () => app(DocumentWorkflow::class)->publish($rendered))->toThrow(RuntimeException::class, 'Storage failed');

    Event::assertDispatched(DocumentRenderFailed::class, fn (DocumentRenderFailed $event) => $event->target === $target
        && $event->message === 'Storage failed');
    Event::assertNotDispatched(DocumentStored::class);
});

it('keeps the generation id across queued job serialization and retries', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/46.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class);
    $job = (new GenerateUniqueDocument($rendered))->withGenerator(FakeDocumentGenerator::class);
    $id = $job->generationId();

    $firstAttempt = unserialize(serialize($job));
    $secondAttempt = unserialize(serialize($job));
    $firstAttempt->handle();
    $secondAttempt->handle();

    Event::assertDispatchedTimes(DocumentRendering::class, 2);
    Event::assertDispatchedTimes(DocumentStored::class, 2);
    expect($firstAttempt->generationId())->toBe($id)
        ->and($secondAttempt->generationId())->toBe($id)
        ->and(Event::dispatched(DocumentStored::class)->map(fn (array $payload) => $payload[0]->generationId)->all())
        ->toBe([$id, $id])
        ->and((new GenerateVersionedDocument($rendered))->generationId())->not->toBe($id)
        ->and((new GenerateVersionedDocument($rendered))->generationId())
        ->not->toBe((new GenerateVersionedDocument($rendered))->generationId());
});

it('retains the job generation id with a captured custom generator', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/46.pdf', 'documents'));
    $job = new GenerateUniqueDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class));

    $job->handle();

    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $job->generationId());
});

it('uses one generation id when a failed attempt is retried successfully', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents'));
    $hasFailed = false;
    app()->bind(DocumentGenerator::class, function () use (&$hasFailed): DocumentGenerator {
        if (! $hasFailed) {
            $hasFailed = true;

            return app(FailingDocumentGenerator::class);
        }

        return app(FakeDocumentGenerator::class);
    });
    $job = new GenerateUniqueDocument(new RenderedDocument('HTML', $target));

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'PDF generation failed');
    $job->handle();

    Event::assertDispatchedTimes(DocumentRendering::class, 2);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatched(DocumentRenderFailed::class, fn (DocumentRenderFailed $event) => $event->generationId === $job->generationId());
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $job->generationId());
    expect(Event::dispatched(DocumentRendering::class)->map(fn (array $payload) => $payload[0]->generationId)->all())
        ->toBe([$job->generationId(), $job->generationId()]);
});

it('uses the queue uuid across retries for legacy jobs without a generation id', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents'));
    $job = new GenerateUniqueDocument(new RenderedDocument('HTML', $target));
    (new ReflectionProperty(AbstractGenerateDocument::class, 'documentGenerationId'))->setValue($job, null);
    (new ReflectionProperty(AbstractGenerateDocument::class, 'generationId'))->setValue($job, null);
    $legacyPayload = serialize($job);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->twice()->andReturn('legacy-queue-uuid');

    $firstAttempt = unserialize($legacyPayload);
    $secondAttempt = unserialize($legacyPayload);
    $firstAttempt->setJob($queueJob);
    $secondAttempt->setJob($queueJob);

    expect($firstAttempt->generationId())->toBe('legacy-queue-uuid')
        ->and($secondAttempt->generationId())->toBe('legacy-queue-uuid');
});

it('assigns a new generation id to each direct save', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/46.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);
    $workflow = app(DocumentWorkflow::class);

    $workflow->publish($rendered);
    $workflow->publish($rendered);

    $started = Event::dispatched(DocumentRendering::class)->map(fn (array $payload) => $payload[0]->generationId)->all();
    $stored = Event::dispatched(DocumentStored::class)->map(fn (array $payload) => $payload[0]->generationId)->all();

    expect($started)->toHaveCount(2)
        ->and($started[0])->not->toBe($started[1])
        ->and($stored)->toBe($started);
});

it('allows a direct save to use an explicit generation id', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/46.pdf', 'documents'));

    app(DocumentWorkflow::class)->publish(new RenderedDocument('HTML', $target), generationId: 'generation-123');

    Event::assertDispatched(DocumentRendering::class, fn (DocumentRendering $event) => $event->generationId === 'generation-123');
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'generation-123');
});

it('does not queue a duplicate generation id for the same unique target', function () {
    Queue::fake();
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/46.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);
    $first = new GenerateUniqueDocument($rendered);
    $duplicate = new GenerateUniqueDocument($rendered);

    dispatch($first);
    dispatch($duplicate);

    expect($duplicate->generationId())->not->toBe($first->generationId());
    Queue::assertPushed(GenerateUniqueDocument::class, 1);
    Queue::assertPushed(GenerateUniqueDocument::class, fn (GenerateUniqueDocument $job) => $job->generationId() === $first->generationId());
});

it('reports listener errors without failing a successful save', function (string $event) {
    Exceptions::fake();
    $listenerError = new RuntimeException('Listener failed');
    Event::listen($event, fn () => throw $listenerError);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/47.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    expect(app(DocumentWorkflow::class)->publish($rendered))->toBeNull()
        ->and(Storage::disk('documents')->get('invoices/47.pdf'))->toBe('rendered PDF');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception === $listenerError);
})->with([
    DocumentRendering::class,
    DocumentStored::class,
]);

it('does not fail a queued document job when a stored listener throws', function () {
    Exceptions::fake();
    $listenerError = new Error('Listener failed after storage');
    Event::listen(DocumentStored::class, fn () => throw $listenerError);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/47.pdf', 'documents'));
    $job = (new GenerateUniqueDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    dispatch_sync($job);

    expect(Storage::disk('documents')->get('invoices/47.pdf'))->toBe('rendered PDF');
    Exceptions::assertReported(fn (Error $exception) => $exception === $listenerError);
});

it('reports a failing failure listener and preserves the rendering exception', function () {
    Exceptions::fake();
    $listenerError = new LogicException('Failure listener failed');
    Event::listen(DocumentRenderFailed::class, fn () => throw $listenerError);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/48.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);
    app()->bind(DocumentGenerator::class, FailingDocumentGenerator::class);

    expect(fn () => app(DocumentWorkflow::class)->publish($rendered))->toThrow(RuntimeException::class, 'PDF generation failed');

    Exceptions::assertReported(fn (LogicException $exception) => $exception === $listenerError);
});

it('supports serializable queued listeners for stored and failed events', function () {
    Queue::fake();
    Event::listen(DocumentStored::class, QueuedDocumentEventListener::class);
    Event::listen(DocumentRenderFailed::class, QueuedDocumentEventListener::class);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/48.pdf', 'documents'));
    $rendered = new RenderedDocument('<h1>Private invoice</h1>', $target);

    app(DocumentWorkflow::class)->publish($rendered);
    app()->bind(DocumentGenerator::class, FailingDocumentGenerator::class);
    expect(fn () => app(DocumentWorkflow::class)->publish($rendered))->toThrow(RuntimeException::class, 'PDF generation failed');

    Queue::assertPushed(CallQueuedListener::class, 2);
    $listenerJobs = Queue::pushed(CallQueuedListener::class);
    $stored = unserialize(serialize($listenerJobs[0]))->data[0];
    $failed = unserialize(serialize($listenerJobs[1]))->data[0];

    expect($stored)->toBeInstanceOf(DocumentStored::class)
        ->and($failed)->toBeInstanceOf(DocumentRenderFailed::class)
        ->and($failed->message)->toBe('PDF generation failed')
        ->and(serialize($listenerJobs[0]))->not->toContain('<h1>Private invoice</h1>')
        ->and(serialize($listenerJobs[1]))->not->toContain('<h1>Private invoice</h1>');
});

it('emits the generation event but not the stored event when only generating PDF contents', function () {
    Event::fake([DocumentRendering::class, DocumentStored::class, DocumentRenderFailed::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/49.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    expect(app(DocumentGenerator::class)->generate($rendered)->contents)->toBe('rendered PDF');

    Event::assertDispatchedOnce(DocumentRendering::class);
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
});

it('rejects an empty generation id', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/50.pdf', 'documents'));
    $rendered = new RenderedDocument('HTML', $target);

    app(DocumentWorkflow::class)->publish($rendered, generationId: '');
})->throws(InvalidArgumentException::class, 'The generation ID cannot be empty.');
