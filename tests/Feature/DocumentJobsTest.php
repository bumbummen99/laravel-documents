<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Jobs\AbstractGenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\GenerateDocumentFailingWithMissingModels;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\GenerateDocumentWithRelatedModel;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueueTables;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;

it('configures the requested connection and queue on a snapshot job', function () {
    $rendered = new RenderedDocument('HTML', new DocumentTarget('disk', new RecordStorageContext(42)));
    $job = app(DocumentJobFactory::class)->make($rendered, connection: 'redis', queue: 'documents');

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('documents')
        ->and($job->timeout)->toBe(60)
        ->and($job->generated())->toBeFalse();
});

it('preserves custom job queue defaults unless explicitly overridden', function (?string $connection, ?string $queue, string $expectedConnection, string $expectedQueue) {
    $rendered = new RenderedDocument('HTML', new DocumentTarget('disk', new RecordStorageContext(42)));
    $prototype = new class($rendered) extends GenerateUniqueDocument
    {
        public function __construct(RenderedDocument $rendered)
        {
            parent::__construct($rendered);
            $this->onConnection('redis')->onQueue('documents');
        }
    };
    $job = app(DocumentJobFactory::class)->make($rendered, connection: $connection, queue: $queue, job: $prototype::class);

    expect($job->connection)->toBe($expectedConnection)
        ->and($job->queue)->toBe($expectedQueue);
})->with([
    'no overrides' => [null, null, 'redis', 'documents'],
    'connection only' => ['sqs', null, 'sqs', 'documents'],
    'queue only' => [null, 'pdf', 'redis', 'pdf'],
    'both overrides' => ['sqs', 'pdf', 'sqs', 'pdf'],
]);

it('uses the custom context identity for unique IDs and stable target locks', function () {
    $context = new RecordStorageContext(42);
    $job = new GenerateUniqueDocument(new RenderedDocument('HTML', new DocumentTarget('records', $context)));
    $lock = Mockery::mock(Lock::class);
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->once()
        ->with('laravel_unique_document:'.hash('xxh128', $context->identity()), 60)
        ->andReturn($lock);
    app('cache')->extend('unique-lock-test', fn () => new Repository($store));
    Config::set('cache.stores.unique-lock-test', ['driver' => 'unique-lock-test']);
    Config::set('cache.default', 'unique-lock-test');

    expect($job->uniqueId())->toBe(hash('xxh128', $context->identity()))
        ->and($job->uniqueVia()->lock(UniqueLock::getKey($job), 60))->toBe($lock);
});

it('releases shared target locks after processing custom jobs with related models', function (bool $deleteWhenMissingModels, bool $modelMissing) {
    Event::fake([JobFailed::class, DocumentStored::class]);
    config([
        'cache.default' => 'document-locks',
        'cache.stores.document-locks' => ['driver' => 'array'],
        'cache.stores.warmup-locks' => ['driver' => 'array'],
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],
        'queue.failed.driver' => 'null',
    ]);

    QueueTables::createJobs();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    $user = User::query()->create();
    $storage = new RecordDocumentStorage;
    app(DocumentStorageManager::class)->register('records', fn () => $storage);
    $rendered = new RenderedDocument('frozen HTML', new DocumentTarget('records', new RecordStorageContext(909)), generator: FakeDocumentGenerator::class);
    $job = $deleteWhenMissingModels
        ? new GenerateDocumentWithRelatedModel($rendered)
        : new GenerateDocumentFailingWithMissingModels($rendered);
    $job->relatedModel = $user;
    $job->withGenerator(FakeDocumentGenerator::class);
    $generationId = $job->generationId();

    Cache::setDefaultDriver('warmup-locks');
    $job->uniqueVia();
    Cache::setDefaultDriver('document-locks');
    dispatch($job);
    expect(DB::table('jobs')->count())->toBe(1);

    if ($modelMissing) {
        $user->delete();
    }

    Cache::forgetDriver('laravel-documents-unique');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])
        ->assertExitCode(0);

    expect(DB::table('jobs')->count())->toBe(0);
    Event::assertDispatchedTimes(JobFailed::class, $modelMissing && ! $deleteWhenMissingModels ? 1 : 0);
    Event::assertDispatchedTimes(DocumentStored::class, $modelMissing ? 0 : 1);

    if (! $modelMissing) {
        Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $generationId);
        expect($storage->get($rendered->target->context))->toBe('rendered PDF');
    }

    foreach (['warmup-locks', 'document-locks'] as $store) {
        $lock = Cache::store($store)->lock('laravel_unique_document:'.$job->uniqueId(), 60);
        expect($lock->get())->toBeTrue();
        $lock->release();
    }

    dispatch(new GenerateUniqueDocument($rendered));

    expect(DB::table('jobs')->count())->toBe(1);
})->with([
    'deleted' => [true, true],
    'failed' => [false, true],
    'completed' => [true, false],
]);

it('preserves the generation identity when a custom job uses model serialization', function () {
    $job = new GenerateDocumentWithRelatedModel(new RenderedDocument(
        'frozen HTML',
        new DocumentTarget('disk', new RecordStorageContext(909)),
    ));

    expect(unserialize(serialize($job))->generationId())->toBe($job->generationId());
});

it('preserves generation identities from legacy native queue payloads', function (string $jobClass) {
    $properties = serialize([
        "\0*\0rendered" => new RenderedDocument('frozen HTML', new DocumentTarget('disk', new RecordStorageContext(909))),
        "\0".AbstractGenerateDocument::class."\0generationId" => 'legacy-generation-id',
    ]);
    $payload = 'O:'.strlen($jobClass).':"'.$jobClass.'":'.substr($properties, 2);

    expect(unserialize($payload)->generationId())->toBe('legacy-generation-id');
})->with([GenerateUniqueDocument::class, GenerateVersionedDocument::class]);
