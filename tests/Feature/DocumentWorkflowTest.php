<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\InvoiceDocument;

function workflowSnapshot(string $html = 'Invoice', string $storage = 'disk'): RenderedDocument
{
    return new RenderedDocument($html, new DocumentTarget(
        $storage,
        new FilesystemDocumentStorageContext('invoices/workflow.pdf', 'documents'),
    ));
}

beforeEach(function () {
    Storage::fake('documents');
    app()->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
});

it('generates through the facade with one snapshot capture and no publication', function () {
    Queue::fake();
    Event::fake([DocumentStored::class]);
    $rendered = workflowSnapshot();
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andReturn($rendered);

    expect(Documents::generate($document)->contents)->toBe('Invoice')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeTrue()
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
    Event::assertNotDispatched(DocumentStored::class);
});

it('publishes through the facade without making consumers capture a render', function () {
    View::addLocation(__DIR__.'/../Fixtures/views');
    Event::fake([DocumentStored::class]);
    $document = new InvoiceDocument(42);

    expect(Documents::publish($document, generationId: 'workflow-generation'))->toBeNull()
        ->and($document->get())->toContain('<h1>Invoice 42</h1>')
        ->and(app(DocumentSnapshotCache::class)->changed($document->render()))->toBeFalse();
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'workflow-generation');
});

it('publishes a document through the explicit outcome API with the supplied generation ID', function () {
    View::addLocation(__DIR__.'/../Fixtures/views');
    Event::fake([DocumentStored::class]);
    $document = new InvoiceDocument(42);

    $result = Documents::publishWithResult($document, generationId: 'workflow-generation');

    expect($result->status)->toBe(DocumentPublicationStatus::Published)
        ->and($result->version)->toBeNull()
        ->and($document->get())->toContain('<h1>Invoice 42</h1>');
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'workflow-generation');
});

it('passes the generated result to its injected publisher', function () {
    $rendered = workflowSnapshot();
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andReturn($rendered);
    $generated = new GeneratedDocument($rendered, 'final PDF', 'generation-123');
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldReceive('generate')->once()->with($rendered, 'generation-123')->andReturn($generated);
    app()->instance(DocumentGenerator::class, $generator);
    $publisher = Mockery::mock(DocumentPublisher::class);
    $publisher->shouldReceive('publish')->once()->with($generated)->andReturn('version-123');
    app()->instance(DocumentPublisher::class, $publisher);

    expect(app(DocumentWorkflow::class)->publish($document, generationId: 'generation-123'))->toBe('version-123');
});

it('selects the explicit job then the document job then the storage default', function (?string $givenJob, ?string $documentJob, string $storage, string $expectedJob, string $method) {
    Queue::fake();
    $rendered = workflowSnapshot(storage: $storage);
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andReturn($rendered);
    $document->shouldReceive('job')->times($givenJob === null ? 1 : 0)->andReturn($documentJob);

    $job = Documents::{$method}($document, connection: 'redis', queue: 'pdf', job: $givenJob);

    expect($job)->toBeInstanceOf($expectedJob)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('pdf')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
})->with([
    'explicit job' => [GenerateUniqueDocument::class, GenerateVersionedDocument::class, 'versioned', GenerateUniqueDocument::class],
    'document job' => [null, GenerateVersionedDocument::class, 'disk', GenerateVersionedDocument::class],
    'disk default' => [null, null, 'disk', GenerateUniqueDocument::class],
    'versioned default' => [null, null, 'versioned', GenerateVersionedDocument::class],
])->with(['makeJob', 'makeJobIfChanged']);

it('creates serializable snapshot jobs without retaining the document', function () {
    Queue::fake();
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andReturn(workflowSnapshot('frozen invoice'));
    $document->shouldReceive('job')->once()->andReturnNull();
    $job = Documents::makeJob($document);
    $serialized = serialize($job);
    Queue::assertNothingPushed();

    unserialize($serialized)->handle();

    expect(Storage::disk('documents')->get('invoices/workflow.pdf'))->toBe('frozen invoice')
        ->and($serialized)->not->toContain($document::class, DocumentWorkflow::class, DocumentPublisher::class);
});

it('conditionally creates configured jobs against a single captured render', function () {
    Queue::fake();
    $rendered = workflowSnapshot();
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->times(3)->andReturn($rendered);
    $document->shouldReceive('job')->twice()->andReturn(GenerateVersionedDocument::class);
    $job = Documents::makeJobIfChanged($document, connection: 'redis', queue: 'pdf');

    expect($job)->toBeInstanceOf(GenerateVersionedDocument::class)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('pdf');
    Documents::publish($document);

    expect(Documents::makeJobIfChanged($document))->toBeNull();
    Queue::assertNothingPushed();
});

it('captures a fresh snapshot on each call to the same workflow', function () {
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->twice()
        ->andReturn(workflowSnapshot('first'), workflowSnapshot('second'));
    $workflow = app(DocumentWorkflow::class);

    expect($workflow->generate($document)->contents)->toBe('first')
        ->and($workflow->generate($document)->contents)->toBe('second');
});

it('allows applications to mock the workflow through its facade', function () {
    $document = new InvoiceDocument(42);
    $generated = new GeneratedDocument(workflowSnapshot(), 'mocked PDF', 'generation-123');
    Documents::shouldReceive('generate')->once()->with($document)->andReturn($generated);

    expect(Documents::generate($document))->toBe($generated);
});

it('propagates snapshot failures without generating PDF bytes', function () {
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andThrow(new RuntimeException('Cannot render document.'));
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(DocumentGenerator::class, $generator);

    expect(fn () => Documents::generate($document))->toThrow(RuntimeException::class, 'Cannot render document.');
});

it('rejects invalid job configurations through the document-level API', function (?string $givenJob, ?string $documentJob) {
    $document = Mockery::mock(Document::class);
    $document->shouldReceive('render')->once()->andReturn(workflowSnapshot());
    $document->shouldReceive('job')->times($givenJob === null ? 1 : 0)->andReturn($documentJob);

    expect(fn () => Documents::makeJob($document, job: $givenJob))
        ->toThrow(InvalidArgumentException::class, 'The job must implement '.GenerateDocument::class.'.');
})->with([
    'explicit job' => [stdClass::class, null],
    'document job' => [null, stdClass::class],
]);
