<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\ConfiguredPdfDriver;

beforeEach(function () {
    Storage::fake('documents');
});

it('reuses the generator for independent snapshots without publication or queue dependencies', function () {
    Event::fake([DocumentStored::class]);
    Queue::fake();
    Config::set('cache.default', 'null');
    app(DocumentPdfDriverManager::class)->register('test', fn () => new ConfiguredPdfDriver('PDF'));
    $target = new DocumentTarget('unregistered-storage', new FilesystemDocumentStorageContext('invoice.pdf', 'documents'));
    $first = new RenderedDocument('first', $target, 'test');
    $second = new RenderedDocument('second', $target, 'test');
    $generator = app(DocumentGenerator::class);

    expect($generator->generate($first)->contents)->toBe('PDF::first')
        ->and($generator->generate($second)->contents)->toBe('PDF::second')
        ->and($first->html)->toBe('first')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Event::assertNotDispatched(DocumentStored::class);
    Queue::assertNothingPushed();
});

it('publishes the supplied generation result without a generator and updates its fingerprint', function () {
    Event::fake([DocumentStored::class]);
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(DocumentGenerator::class, $generator);

    $generated = new GeneratedDocument($rendered, 'final PDF', 'generation-123');

    expect(app(DocumentPublisher::class)->publish($generated))->toBeNull()
        ->and(Storage::disk('documents')->get('invoice.pdf'))->toBe('final PDF')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === 'generation-123');
});

it('constructs jobs without generating or dispatching a document', function () {
    Queue::fake();
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(DocumentGenerator::class, $generator);
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'versioned',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));

    $job = app(DocumentJobFactory::class)->make($rendered, connection: 'redis', queue: 'pdf');

    expect($job)->toBeInstanceOf(GenerateVersionedDocument::class)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('pdf')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});

it('conditionally creates jobs using the last published render', function () {
    $rendered = new RenderedDocument('frozen', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));
    $jobs = app(DocumentJobFactory::class);
    $job = $jobs->makeIfChanged($rendered, connection: 'redis', queue: 'pdf', job: GenerateVersionedDocument::class);

    expect($job)->toBeInstanceOf(GenerateVersionedDocument::class)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('pdf');

    app(DocumentPublisher::class)->publish(new GeneratedDocument($rendered, 'final PDF', 'generation-123'));

    expect($jobs->makeIfChanged($rendered))->toBeNull()
        ->and($jobs->make($rendered))->toBeInstanceOf(GenerateUniqueDocument::class);
});

it('reuses a publisher across generation results without retaining their targets or generation ids', function () {
    Event::fake([DocumentStored::class]);
    $first = new RenderedDocument('first', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('first.pdf', 'documents'),
    ));
    $second = new RenderedDocument('second', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('second.pdf', 'documents'),
    ));
    $publisher = app(DocumentPublisher::class);

    $publisher->publish(new GeneratedDocument($first, 'first PDF', 'first-generation'));
    $publisher->publish(new GeneratedDocument($second, 'second PDF', 'second-generation'));

    expect(Storage::disk('documents')->get('first.pdf'))->toBe('first PDF')
        ->and(Storage::disk('documents')->get('second.pdf'))->toBe('second PDF')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($second))->toBeFalse();
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->target === $first->target
        && $event->generationId === 'first-generation');
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->target === $second->target
        && $event->generationId !== 'first-generation');
});
