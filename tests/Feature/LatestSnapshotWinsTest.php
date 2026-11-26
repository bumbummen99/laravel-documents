<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Events\DocumentSuperseded;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FailingSnapshotCacheStore;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;

function latestSnapshot(string $html, ?bool $enabled = true, string $path = 'latest.pdf'): RenderedDocument
{
    return new RenderedDocument(
        $html,
        new DocumentTarget('disk', new FilesystemDocumentStorageContext($path, 'documents')),
        latestSnapshotWins: $enabled,
    );
}

beforeEach(function () {
    Storage::fake('documents');
    app()->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
});

it('prevents a serialized queued snapshot from overwriting a newer HTTP publication', function () {
    $older = latestSnapshot('queued');
    $job = new GenerateUniqueDocument($older);
    $payload = serialize($job);
    $newer = latestSnapshot('HTTP');
    Route::get('/latest-document', fn () => response(Documents::publish($newer) ?? 'published'));
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    $this->get('/latest-document')->assertOk();
    $restored = unserialize($payload);
    $restored->handle();

    expect(Storage::disk('documents')->get('latest.pdf'))->toBe('HTTP')
        ->and(app(DocumentSnapshotCache::class)->changed($newer))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($older))->toBeTrue();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentSuperseded::class);
    Event::assertDispatched(DocumentSuperseded::class, fn (DocumentSuperseded $event) => $event->generationId === $job->generationId()
        && $event->snapshotId === $older->snapshotId
        && $event->latestSnapshotId === $newer->snapshotId);
});

it('checks supersession for custom version-returning storage without creating stale versions', function () {
    $target = new DocumentTarget('custom', new FilesystemDocumentStorageContext('latest.pdf', 'documents'));
    $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
    $newer = new RenderedDocument('newer', $target, latestSnapshotWins: true);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->with($target->context, 'newer')->andReturn('version-newer');
    app(DocumentStorageManager::class)->register('custom', fn () => $storage, GenerateVersionedDocument::class);
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    expect(Documents::publish($newer))->toBe('version-newer')
        ->and(Documents::publish($older))->toBeNull()
        ->and(app(DocumentSnapshotCache::class)->changed($newer))->toBeFalse();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentSuperseded::class);
});

it('distinguishes a superseded publication from an already stored matching PDF', function () {
    $older = latestSnapshot('A');
    Documents::publish($older);
    $newer = latestSnapshot('B');
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    $result = Documents::publishWithResult($older);

    expect($result->status)->toBe(DocumentPublicationStatus::Superseded)
        ->and($result->version)->toBeNull()
        ->and(Storage::disk('documents')->get('latest.pdf'))->toBe('A')
        ->and(app(DocumentSnapshotCache::class)->changed($older))->toBeFalse()
        ->and(app(DocumentSnapshotCache::class)->changed($newer))->toBeTrue();
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentSuperseded::class);
});

it('reports successful unversioned publication separately from supersession', function () {
    $result = Documents::publishWithResult(latestSnapshot('current'));

    expect($result->status)->toBe(DocumentPublicationStatus::Published)
        ->and($result->version)->toBeNull()
        ->and(Storage::disk('documents')->get('latest.pdf'))->toBe('current');
});

it('reports the version of a successful publication', function () {
    $target = new DocumentTarget('custom', new FilesystemDocumentStorageContext('latest.pdf', 'documents'));
    $rendered = new RenderedDocument('current', $target, latestSnapshotWins: true);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('write')->once()->with($target->context, 'current')->andReturn('version-current');
    app(DocumentStorageManager::class)->register('custom', fn () => $storage, GenerateVersionedDocument::class);

    $result = Documents::publishWithResult($rendered);

    expect($result->status)->toBe(DocumentPublicationStatus::Published)
        ->and($result->version)->toBe('version-current');
});

it('supersedes an older result even when the newer snapshot is never generated or dispatched', function () {
    $older = Documents::generate(latestSnapshot('older'));
    latestSnapshot('newer');
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    expect(Documents::publish(unserialize(serialize($older))))->toBeNull()
        ->and(Storage::disk('documents')->exists('latest.pdf'))->toBeFalse();
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentSuperseded::class);
});

it('does not allow older work to publish after a newer generation fails', function () {
    $older = latestSnapshot('older');
    $newer = latestSnapshot('newer');
    $generator = Mockery::mock(DocumentGenerator::class);
    $generator->shouldReceive('generate')->with($newer, null)->andThrow(new RuntimeException('Generation failed.'));
    app()->instance(DocumentGenerator::class, $generator);
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    expect(fn () => Documents::publish($newer))->toThrow(RuntimeException::class, 'Generation failed.');
    Documents::publish(new GeneratedDocument($older, 'old PDF', 'older'));

    expect(Storage::disk('documents')->allFiles())->toBeEmpty();
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertDispatchedOnce(DocumentSuperseded::class);
});

it('allows superseded listeners to render and publish without holding the target lock', function () {
    Config::set('laravel-documents.snapshot_cache.lock_wait_seconds', 1);
    $older = latestSnapshot('older');
    latestSnapshot('newer');
    Event::listen(DocumentSuperseded::class, fn () => Documents::publish(latestSnapshot('listener')));

    Documents::publish($older);

    expect(Storage::disk('documents')->get('latest.pdf'))->toBe('listener');
});

it('allows publication retries of the latest snapshot without registering it again', function () {
    $rendered = latestSnapshot('latest');
    $generated = unserialize(serialize(Documents::generate($rendered)));
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);
    Documents::publish($generated);
    Documents::publish($generated);

    expect($generated->snapshotId)->toBe($rendered->snapshotId)
        ->and(Storage::disk('documents')->get('latest.pdf'))->toBe('latest');
    Event::assertDispatchedTimes(DocumentStored::class, 2);
    Event::assertNotDispatched(DocumentSuperseded::class);
});

it('allows an already queued snapshot to publish after an equivalent snapshot is rendered', function () {
    $older = latestSnapshot('same');
    $job = Documents::makeJob($older);
    $newer = latestSnapshot('same');
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);
    $job->handle();

    expect($older->snapshotId)->toBe($newer->snapshotId)
        ->and($older->fingerprint())->toBe($newer->fingerprint())
        ->and(Storage::disk('documents')->get('latest.pdf'))->toBe('same');
    expect(Documents::makeJobIfChanged(latestSnapshot('same')))->toBeNull();
    Event::assertDispatchedOnce(DocumentStored::class);
    Event::assertNotDispatched(DocumentSuperseded::class);
});

it('does not revive a superseded snapshot when its content becomes current again', function () {
    $original = latestSnapshot('A');
    $intermediate = latestSnapshot('B');
    $current = latestSnapshot('A');
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    Documents::publish($original);
    Documents::publish($intermediate);
    expect(Storage::disk('documents')->exists('latest.pdf'))->toBeFalse();

    Documents::publish($current);

    expect($original->snapshotId)->not->toBe($current->snapshotId)
        ->and(Storage::disk('documents')->get('latest.pdf'))->toBe('A');
    Event::assertDispatchedTimes(DocumentSuperseded::class, 2);
    Event::assertDispatchedOnce(DocumentStored::class);
});

it('keeps the accepted unique job eligible when an equivalent duplicate is blocked', function () {
    $older = latestSnapshot('same');
    $job = new GenerateUniqueDocument($older);
    $locks = new UniqueLock(Cache::store());
    expect($locks->acquire($job))->toBeTrue();

    try {
        $duplicate = new GenerateUniqueDocument(latestSnapshot('same'));
        expect($locks->acquire($duplicate))->toBeFalse();

        $job->handle();

        expect(Storage::disk('documents')->get('latest.pdf'))->toBe('same');
    } finally {
        $locks->release($job);
    }
});

it('supersedes legacy snapshot markers when registering a fingerprinted snapshot', function () {
    $snapshot = latestSnapshot('same');
    $cache = app(DocumentSnapshotCache::class);
    $legacy = $cache->register($snapshot->target);
    $new = latestSnapshot('same');

    expect($new->snapshotId)->not->toBe($legacy)
        ->and($new->snapshotId)->not->toBe($snapshot->snapshotId);
    Documents::publish($snapshot);
    expect(Storage::disk('documents')->exists('latest.pdf'))->toBeFalse();
    Documents::publish($new);
    expect(Storage::disk('documents')->get('latest.pdf'))->toBe('same');
});

it('treats a different PDF driver as a different latest snapshot', function () {
    $original = latestSnapshot('same');
    $newer = new RenderedDocument('same', $original->target, 'other-driver', latestSnapshotWins: true);
    Event::fake([DocumentStored::class, DocumentSuperseded::class]);

    Documents::publish($original);

    expect($original->snapshotId)->not->toBe($newer->snapshotId)
        ->and(Storage::disk('documents')->exists('latest.pdf'))->toBeFalse();
    Event::assertDispatchedOnce(DocumentSuperseded::class);
});

it('preserves publication order by default and supports a global default with explicit overrides', function () {
    expect(latestSnapshot('default', null)->snapshotId)->toBeNull();
    Config::set('laravel-documents.latest_snapshot_wins', true);
    expect(latestSnapshot('global', null)->snapshotId)->toBeString()
        ->and(latestSnapshot('override', false)->snapshotId)->toBeNull();
    Config::set('laravel-documents.latest_snapshot_wins', false);
    $older = latestSnapshot('older', null);
    Documents::publish(latestSnapshot('newer', null));
    Documents::publish($older);

    expect(Storage::disk('documents')->get('latest.pdf'))->toBe('older');
});

it('registers document snapshots after HTML has been captured and honors document overrides', function () {
    $document = new class extends Document
    {
        public static ?bool $latestSnapshotWins = true;

        public function html(): string
        {
            expect(Storage::disk('documents')->allFiles())->toBeEmpty();

            return 'captured HTML';
        }

        public function path(): string
        {
            return 'latest.pdf';
        }

        public function disk(): ?string
        {
            return 'documents';
        }
    };
    $rendered = $document->render();
    expect($rendered->snapshotId)->toBeString()
        ->and(unserialize(serialize($rendered)))->toEqual($rendered);
    Config::set('laravel-documents.latest_snapshot_wins', true);
    $document::$latestSnapshotWins = false;
    expect($document->render()->snapshotId)->toBeNull();
});

it('coordinates registration with the publication lock and isolates different targets', function () {
    Config::set('laravel-documents.snapshot_cache.lock_wait_seconds', 1);
    $rendered = latestSnapshot('first');
    $cache = app(DocumentSnapshotCache::class);
    $cache->publish(new GeneratedDocument($rendered, 'PDF', 'first'), function () {
        expect(fn () => latestSnapshot('same target'))->toThrow(LockTimeoutException::class)
            ->and(latestSnapshot('different target', path: 'other.pdf')->snapshotId)->toBeString();

        return null;
    });
    expect($cache->changed($rendered))->toBeFalse();
});

it('fails closed when the request marker is missing instead of reviving an old result', function () {
    $rendered = latestSnapshot('old');
    app('cache')->store()->flush();
    Event::fake([DocumentStored::class, DocumentSuperseded::class, DocumentRenderFailed::class]);

    expect(fn () => app(DocumentPublisher::class)->publish(new GeneratedDocument($rendered, 'PDF', 'old')))
        ->toThrow(RuntimeException::class, 'The latest document snapshot marker is missing.')
        ->and(Storage::disk('documents')->exists('latest.pdf'))->toBeFalse();
    Event::assertNotDispatched(DocumentStored::class);
    Event::assertNotDispatched(DocumentSuperseded::class);
    Event::assertDispatchedOnce(DocumentRenderFailed::class);
});

it('propagates registration failures before generation or queue creation', function () {
    $store = new FailingSnapshotCacheStore;
    app('cache')->extend('latest-failing', fn () => new Repository($store));
    Config::set('cache.stores.latest-failing', ['driver' => 'latest-failing']);
    Config::set('laravel-documents.snapshot_cache.store', 'latest-failing');
    $store->failWrite = true;

    expect(fn () => latestSnapshot('failed'))
        ->toThrow(RuntimeException::class, 'Failed to register the latest document snapshot.')
        ->and(Storage::disk('documents')->allFiles())->toBeEmpty();
});

it('rejects invalid global policy values before registering a snapshot', function (mixed $value) {
    Config::set('laravel-documents.latest_snapshot_wins', $value);

    expect(fn () => latestSnapshot('invalid', null))
        ->toThrow(InvalidArgumentException::class, 'The latest snapshot wins option must be a boolean.');
})->with(['string' => ['true'], 'integer' => [1], 'null' => [null], 'array' => [[]]]);
