<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\InvoiceDocument;

beforeEach(function () {
    View::addLocation(__DIR__.'/../Fixtures/views');
    Storage::fake('documents');
});

it('queues the job configured on the document', function () {
    $document = new class(20) extends InvoiceDocument
    {
        public static ?string $job = GenerateVersionedDocument::class;
    };

    expect(app(DocumentJobFactory::class)->make($document->render(), job: $document->job()))->toBeInstanceOf(GenerateVersionedDocument::class)
        ->and(app(DocumentJobFactory::class)->make((new InvoiceDocument(20))->render()))->toBeInstanceOf(GenerateUniqueDocument::class);
});

it('allows callers to override the document job when creating a job', function () {
    $document = new class(21) extends InvoiceDocument
    {
        public static ?string $job = GenerateVersionedDocument::class;
    };

    expect(app(DocumentJobFactory::class)->make($document->render(), job: GenerateUniqueDocument::class))
        ->toBeInstanceOf(GenerateUniqueDocument::class);
});

it('rejects jobs that are not document jobs', function (?string $documentJob, ?string $givenJob) {
    $document = new class(22) extends InvoiceDocument {};
    $document::$job = $documentJob;

    try {
        app(DocumentJobFactory::class)->make($document->render(), job: $givenJob ?? $document->job());
    } finally {
        $document::$job = null;
    }
})->with([
    'document job' => [stdClass::class, null],
    'given job' => [null, stdClass::class],
])->throws(InvalidArgumentException::class, 'The job must implement '.GenerateDocument::class.'.');

it('exposes the stored version on the job after it ran', function () {
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andReturn('revision-7');
    app(DocumentStorageManager::class)->register('custom', fn () => $storage);
    $target = new DocumentTarget('custom', new FilesystemDocumentStorageContext('invoices/23.pdf', 'documents'));
    $job = (new GenerateVersionedDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    expect($job->generated())->toBeFalse()
        ->and($job->version())->toBeNull();

    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and($job->version())->toBe('revision-7');
});

it('marks unversioned documents as generated without a version', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/24.pdf', 'documents'));
    $job = (new GenerateUniqueDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class);

    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and($job->version())->toBeNull();
});

it('does not mark a failed generation as generated', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/25.pdf', 'documents'));
    $job = (new GenerateVersionedDocument(new RenderedDocument('HTML', $target, generator: FailingDocumentGenerator::class)))
        ->withGenerator(FailingDocumentGenerator::class);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'PDF generation failed')
        ->and($job->generated())->toBeFalse();
});

it('skips rendering when its batch was cancelled', function () {
    Event::fake([DocumentRendering::class]);
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/26.pdf', 'documents'));
    [$job] = (new GenerateVersionedDocument(new RenderedDocument('HTML', $target, generator: FakeDocumentGenerator::class)))
        ->withGenerator(FakeDocumentGenerator::class)
        ->withFakeBatch(cancelledAt: now()->toImmutable());

    $job->handle();

    expect($job->generated())->toBeFalse()
        ->and(Storage::disk('documents')->exists('invoices/26.pdf'))->toBeFalse();
    Event::assertNotDispatched(DocumentRendering::class);
});

it('tracks versioned document jobs in a batch', function () {
    Config::set('queue.default', 'sync');
    Config::set('queue.batching.database', 'testing');
    Schema::create('job_batches', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    $jobs = collect([27, 28])->map(fn (int $number) => (new GenerateVersionedDocument(
        new RenderedDocument('HTML', new DocumentTarget('disk', new FilesystemDocumentStorageContext("invoices/{$number}.pdf", 'documents')), generator: FakeDocumentGenerator::class),
    ))->withGenerator(FakeDocumentGenerator::class));

    $batch = Bus::batch($jobs->all())->dispatch();

    expect(Bus::findBatch($batch->id)->progress())->toBe(100)
        ->and(Storage::disk('documents')->get('invoices/27.pdf'))->toBe('rendered PDF')
        ->and(Storage::disk('documents')->get('invoices/28.pdf'))->toBe('rendered PDF');
});

it('rejects unique document jobs in a batch', function () {
    $job = new GenerateUniqueDocument((new InvoiceDocument(29))->render());

    $job->withBatchId('batch-id');
})->throws(LogicException::class, 'Unique document jobs cannot be batched. Use '.GenerateVersionedDocument::class.' or dispatch the job individually.');
