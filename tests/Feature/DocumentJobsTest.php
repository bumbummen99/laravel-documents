<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Config;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
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

    expect($job->uniqueId())->toBe('records:42')
        ->and($job->uniqueVia()->lock('framework-job-key', 60))->toBe($lock);
});
