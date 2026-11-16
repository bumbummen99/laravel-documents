<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingSnapshotCacheStore;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;

function cachedSnapshot(string $html, string $storage = 'disk'): RenderedDocument
{
    return new RenderedDocument(
        $html,
        new DocumentTarget($storage, new FilesystemDocumentStorageContext('invoices/cache.pdf', 'documents')),
    );
}

beforeEach(function () {
    Storage::fake('documents');
    app()->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
    $store = new FailingSnapshotCacheStore;
    app()->instance(FailingSnapshotCacheStore::class, $store);
    app('cache')->extend('snapshot-test', fn () => new Repository($store));
    Config::set('cache.stores.snapshot-test', ['driver' => 'snapshot-test']);
    Config::set('laravel-documents.snapshot_cache.store', 'snapshot-test');
});

it('retains the last successful snapshot when PDF generation fails before publication', function () {
    $first = cachedSnapshot('first');
    app(DocumentWorkflow::class)->publish($first);
    $failedSnapshot = cachedSnapshot('second');
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldReceive('generate')->once()->with($failedSnapshot, null)->andThrow(new RuntimeException('Generation failed.'));
    app()->instance(DocumentGenerator::class, $generator);

    expect(fn () => app(DocumentWorkflow::class)->publish($failedSnapshot))->toThrow(RuntimeException::class, 'Generation failed.')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($failedSnapshot))->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('first');
});

it('retains the cached snapshot when a storage factory fails before writing', function () {
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andReturn('version-1');
    app(DocumentStorageManager::class)->register('unstable', fn () => $storage, GenerateVersionedDocument::class);
    $first = cachedSnapshot('first', 'unstable');
    app(DocumentWorkflow::class)->publish($first);
    app(DocumentStorageManager::class)->register(
        'unstable',
        fn () => throw new RuntimeException('Storage configuration failed.'),
        GenerateVersionedDocument::class,
    );

    expect(fn () => app(DocumentWorkflow::class)->publish(cachedSnapshot('second', 'unstable')))
        ->toThrow(RuntimeException::class, 'Storage configuration failed.')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse();
});

it('invalidates the previous fingerprint before a storage failure', function () {
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->andReturn('version-1');
    $storage->shouldReceive('write')->once()->andThrow(new RuntimeException('Storage failed.'));
    app(DocumentStorageManager::class)->register('unstable', fn () => $storage, GenerateVersionedDocument::class);
    $first = cachedSnapshot('first', 'unstable');
    $second = cachedSnapshot('second', 'unstable');
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);

    expect(app(DocumentWorkflow::class)->publish($first))->toBe('version-1')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse();
    expect(fn () => app(DocumentWorkflow::class)->publish($second))->toThrow(RuntimeException::class, 'Storage failed.')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeTrue()
        ->and(app(DocumentSnapshotCache::class)->changed($second))->toBeTrue();

    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
});

it('refuses to publish if an existing fingerprint cannot be invalidated', function () {
    $first = cachedSnapshot('first');
    $second = cachedSnapshot('second');
    app(DocumentWorkflow::class)->publish($first);
    app(FailingSnapshotCacheStore::class)->failDelete = true;

    expect(fn () => app(DocumentWorkflow::class)->publish($second))->toThrow(RuntimeException::class, 'Failed to invalidate the previous document snapshot.')
        ->and(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('first')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($second))->toBeTrue();
});

it('reports a cache write failure after storage without retrying the published job', function (string $failure) {
    $first = cachedSnapshot('first');
    app(DocumentWorkflow::class)->publish($first);
    app(FailingSnapshotCacheStore::class)->{$failure} = true;
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $job = (new GenerateVersionedDocument(cachedSnapshot('second')))->withGenerator(HtmlDocumentGenerator::class);

    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('second')
        ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeTrue()
        ->and(app(DocumentSnapshotCache::class)->changed(cachedSnapshot('second')))->toBeTrue();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Exceptions::assertReported(RuntimeException::class);
})->with([
    'store returns false' => ['failWrite'],
    'store throws' => ['throwOnWrite'],
]);

it('serializes competing publications and records publication order', function () {
    Config::set('laravel-documents.snapshot_cache.lock_wait_seconds', 1);
    $cache = app(DocumentSnapshotCache::class);
    $first = cachedSnapshot('first');
    $second = cachedSnapshot('second');
    $secondWrote = false;

    $version = $cache->publish(new GeneratedDocument($first, 'first PDF', 'generation-1'), function () use ($cache, $second, &$secondWrote) {
        expect(fn () => $cache->publish(new GeneratedDocument($second, 'second PDF', 'generation-2'), function () use (&$secondWrote) {
            $secondWrote = true;

            return 'version-2';
        }))->toThrow(LockTimeoutException::class);

        return 'version-1';
    });

    expect($version)->toBe('version-1')
        ->and($secondWrote)->toBeFalse()
        ->and($cache->changed($first))->toBeFalse()
        ->and($cache->changed($second))->toBeTrue();

    $cache->publish(new GeneratedDocument($second, 'second PDF', 'generation-2'), fn () => 'version-2');

    expect($cache->changed($first))->toBeTrue()
        ->and($cache->changed($second))->toBeFalse();
});

it('reports a lock release error after publication without failing the job', function () {
    $store = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function release()
                {
                    parent::release();

                    throw new RuntimeException('Snapshot lock release failed.');
                }
            };
        }
    };
    app('cache')->extend('snapshot-release-error', fn () => new Repository($store));
    Config::set('cache.stores.snapshot-release-error', ['driver' => 'snapshot-release-error']);
    Config::set('laravel-documents.snapshot_cache.store', 'snapshot-release-error');
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $job = (new GenerateVersionedDocument(cachedSnapshot('stored')))->withGenerator(HtmlDocumentGenerator::class);

    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('stored')
        ->and(app(DocumentSnapshotCache::class)->changed(cachedSnapshot('stored')))->toBeFalse();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Snapshot lock release failed.');
});

it('reports a disk lock release error after writing without failing the job', function () {
    $store = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function release()
                {
                    parent::release();

                    throw new RuntimeException('Disk lock release failed.');
                }
            };
        }
    };
    app('cache')->extend('disk-release-error', fn () => new Repository($store));
    Config::set('cache.stores.disk-release-error', ['driver' => 'disk-release-error']);
    Config::set('cache.default', 'disk-release-error');
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $rendered = cachedSnapshot('stored');
    $job = (new GenerateVersionedDocument($rendered))->withGenerator(HtmlDocumentGenerator::class);

    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('stored')
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Disk lock release failed.');
});

it('reports a false lock release without retrying a stored document or forcing the lock', function (string $stage) {
    $store = app(FailingSnapshotCacheStore::class);
    $store->failRelease = true;
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);

    if ($stage === 'snapshot') {
        $storage = Mockery::mock(DocumentStorage::class);
        $storage->shouldReceive('write')->once()->andReturn('version-1');
        app(DocumentStorageManager::class)->register('release-test', fn () => $storage);
        $rendered = cachedSnapshot('stored', 'release-test');
        $version = 'version-1';
        $lockKey = 'laravel_documents:snapshot:'.hash('sha256', $rendered->target->context->identity()).':lock';
        $message = 'Failed to release the document snapshot lock.';
    } else {
        Config::set('cache.default', 'snapshot-test');
        Config::set('laravel-documents.snapshot_cache.store', 'array');
        $rendered = cachedSnapshot('stored');
        $version = null;
        $lockKey = DiskDocumentStorage::writeLockKey($rendered->target->context);
        $message = 'Failed to release the disk document write lock.';
    }

    $job = (new GenerateVersionedDocument($rendered))->withGenerator(HtmlDocumentGenerator::class);
    $job->handle();

    expect($job->generated())->toBeTrue()
        ->and($job->version())->toBe($version)
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse()
        ->and($store->locks)->toHaveKey($lockKey);

    if ($stage === 'disk') {
        expect(Storage::disk('documents')->get('invoices/cache.pdf'))->toBe('stored');
    }

    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->version === $version);
    Event::assertNotDispatched(DocumentRenderFailed::class);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === $message);
})->with(['snapshot', 'disk']);

it('preserves the write exception when releasing its lock also fails', function (string $stage, bool $throws) {
    $store = app(FailingSnapshotCacheStore::class);
    $store->failRelease = ! $throws;
    $store->throwOnRelease = $throws;
    Exceptions::fake();
    Event::fake([DocumentStored::class, DocumentRenderFailed::class]);
    $storageFailure = new RuntimeException('Original storage failure.');

    if ($stage === 'snapshot') {
        $storage = Mockery::mock(DocumentStorage::class);
        $storage->shouldReceive('write')->once()->andThrow($storageFailure);
        app(DocumentStorageManager::class)->register('release-test', fn () => $storage);
        $rendered = cachedSnapshot('failed', 'release-test');
        $message = 'Failed to release the document snapshot lock.';
    } else {
        Config::set('cache.default', 'snapshot-test');
        Config::set('laravel-documents.snapshot_cache.store', 'array');
        $files = Mockery::mock(FilesystemAdapter::class);
        $files->shouldReceive('put')->once()->andThrow($storageFailure);
        Storage::shouldReceive('disk')->once()->with('documents')->andReturn($files);
        $rendered = cachedSnapshot('failed');
        $message = 'Failed to release the disk document write lock.';
    }

    $job = (new GenerateVersionedDocument($rendered))->withGenerator(HtmlDocumentGenerator::class);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Original storage failure.')
        ->and($job->generated())->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === ($throws ? 'Lock release threw.' : $message));
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
    Event::assertDispatched(DocumentRenderFailed::class, fn (DocumentRenderFailed $event) => $event->message === 'Original storage failure.');
})->with(['snapshot', 'disk'])->with([
    'returns false' => [false],
    'throws' => [true],
]);

it('rejects a null cache store before writing a document', function () {
    Config::set('cache.stores.snapshot-null', ['driver' => 'null']);
    Config::set('laravel-documents.snapshot_cache.store', 'snapshot-null');
    $rendered = cachedSnapshot('content');

    expect(fn () => app(DocumentSnapshotCache::class)->changed($rendered))
        ->toThrow(LogicException::class, 'must persist values and support atomic locks');
    expect(fn () => app(DocumentJobFactory::class)->makeIfChanged($rendered))
        ->toThrow(LogicException::class, 'must persist values and support atomic locks');
    expect(fn () => app(DocumentWorkflow::class)->publish($rendered))
        ->toThrow(LogicException::class, 'must persist values and support atomic locks');
    expect(Storage::disk('documents')->exists('invoices/cache.pdf'))->toBeFalse();
});

it('rejects a null default cache for disk write locks', function () {
    Config::set('cache.stores.no-locks', ['driver' => 'null']);
    Config::set('cache.default', 'no-locks');
    $context = new FilesystemDocumentStorageContext('invoices/cache.pdf', 'documents');

    expect(fn () => (new DiskDocumentStorage)->write($context, 'PDF'))
        ->toThrow(LogicException::class, 'Disk document storage requires a default cache store with atomic locks.');
    expect(Storage::disk('documents')->exists($context->path))->toBeFalse();
});

it('leaves legacy sidecars untouched and compares cached snapshots instead', function () {
    Storage::disk('documents')->put('invoices/cache.pdf.hash', 'legacy');
    $rendered = cachedSnapshot('content');

    expect(app(DocumentSnapshotCache::class)->changed($rendered))->toBeTrue();
    app(DocumentWorkflow::class)->publish($rendered);

    expect(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse()
        ->and(Storage::disk('documents')->get('invoices/cache.pdf.hash'))->toBe('legacy');
});

it('rejects invalid snapshot cache store names', function (mixed $name) {
    Config::set('laravel-documents.snapshot_cache.store', $name);

    expect(fn () => app(DocumentSnapshotCache::class)->changed(cachedSnapshot('HTML')))
        ->toThrow(InvalidArgumentException::class, 'The document snapshot cache store must be a non-empty name or null.');
})->with([
    'empty string' => [''],
    'integer' => [12],
    'boolean' => [false],
    'array' => [[]],
]);

it('requires positive integer publication lock settings', function (string $option, mixed $value) {
    Config::set('laravel-documents.snapshot_cache.'.$option, $value);

    expect(fn () => app(DocumentSnapshotCache::class)->publish(new GeneratedDocument(cachedSnapshot('HTML'), 'PDF', 'generation-1'), fn () => null))
        ->toThrow(InvalidArgumentException::class, "The document snapshot cache option [{$option}] must be a positive integer.");
})->with(['lock_seconds', 'lock_wait_seconds'])->with([
    'zero' => [0],
    'negative' => [-1],
    'numeric string' => ['30'],
    'fraction' => [1.5],
    'null' => [null],
    'boolean' => [false],
]);

it('rejects persistent cache stores without atomic locks', function (string $operation, string $message) {
    $store = Mockery::mock(Store::class);
    app('cache')->extend('non-locking', fn () => new Repository($store));
    Config::set('cache.stores.non-locking', ['driver' => 'non-locking']);
    Config::set('cache.default', 'non-locking');
    Config::set('laravel-documents.snapshot_cache.store', 'non-locking');
    $rendered = cachedSnapshot('HTML');
    $call = match ($operation) {
        'comparison' => fn () => app(DocumentSnapshotCache::class)->changed($rendered),
        'publication' => fn () => app(DocumentSnapshotCache::class)->publish(new GeneratedDocument($rendered, 'PDF', 'generation-1'), fn () => null),
        'disk write' => fn () => (new DiskDocumentStorage)->write($rendered->target->context, 'PDF'),
        'unique job' => fn () => (new GenerateUniqueDocument($rendered))->uniqueVia(),
    };

    expect($call)->toThrow(LogicException::class, $message);
})->with([
    'comparison' => ['comparison', 'The document snapshot cache must persist values and support atomic locks.'],
    'publication' => ['publication', 'The document snapshot cache must persist values and support atomic locks.'],
    'disk write' => ['disk write', 'Disk document storage requires a default cache store with atomic locks.'],
    'unique job' => ['unique job', 'Unique document jobs require a default cache store with atomic locks.'],
]);

it('publishes different targets independently with stable separate lock and fingerprint keys', function () {
    Config::set('laravel-documents.snapshot_cache.lock_wait_seconds', 1);
    $cache = app(DocumentSnapshotCache::class);
    $store = app(FailingSnapshotCacheStore::class);
    $first = cachedSnapshot('first');
    $second = new RenderedDocument('second', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoices/second.pdf', 'documents'),
    ));
    $firstKey = 'laravel_documents:snapshot:'.hash('sha256', $first->target->context->identity());
    $secondKey = 'laravel_documents:snapshot:'.hash('sha256', $second->target->context->identity());

    $version = $cache->publish(new GeneratedDocument($first, 'first PDF', 'generation-1'), function () use ($cache, $second) {
        expect($cache->publish(new GeneratedDocument($second, 'second PDF', 'generation-2'), fn () => 'version-2'))->toBe('version-2');

        return 'version-1';
    });

    expect($version)->toBe('version-1')
        ->and($cache->changed($first))->toBeFalse()
        ->and($cache->changed($second))->toBeFalse()
        ->and($store->lockNames)->toBe([$firstKey.':lock', $secondKey.':lock'])
        ->and($store->fingerprints)->toBe([$secondKey => $second->fingerprint(), $firstKey => $first->fingerprint()]);
});
