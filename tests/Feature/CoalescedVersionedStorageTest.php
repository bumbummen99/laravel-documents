<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentTargetStatus;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;
use SkyRaptor\LaravelVersionedStorage\StagedVersion;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

require_once __DIR__.'/../Fixtures/VersionedIntegration.php';

beforeEach(function () {
    if (! class_exists(VersionedStorage::class)
        || ! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! extension_loaded('sockets')) {
        $this->markTestSkipped('Install versioned storage and its Unix extensions to run these integration tests.');
    }

    $this->app->register(VersionedStorageServiceProvider::class);
    $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    $this->app->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    Storage::fake('documents');
});

it('restores the connection when a snapshot capture fails during commit', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/commit-failure.pdf', 'documents'));
        $connection = DB::connection();
        Event::listen(TransactionCommitting::class, static fn () => throw new RuntimeException('commit refused'));

        try {
            expect(fn () => new RenderedDocument('first', $target, latestSnapshotWins: true))
                ->toThrow(RuntimeException::class, 'commit refused');
        } finally {
            Event::forget(TransactionCommitting::class);
        }

        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->getPdo()->inTransaction())->toBeFalse()
            ->and(DB::table('document_coalescing_targets')->count())->toBe(0);
        new RenderedDocument('retry', $target, latestSnapshotWins: true);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
    });
});

it('tombstones deleted targets until explicitly restored', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/deleted-permanently.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $old = new RenderedDocument('old', $target, latestSnapshotWins: true);
        Documents::publishWithResult($old);
        Documents::tombstoneTarget($target);
        expect((new VersionedDocumentStorage)->get($context))->toBe('old');
        $document = new class($target) extends Document
        {
            public static ?bool $latestSnapshotWins = true;

            public function __construct(private DocumentTarget $target) {}

            public function html(): string
            {
                return 'direct';
            }

            public function target(): DocumentTarget
            {
                return $this->target;
            }

            public function path(): string
            {
                return 'unused';
            }
        };

        expect(fn () => new RenderedDocument('stale request', $target, latestSnapshotWins: true))
            ->toThrow(LogicException::class, 'deleted');
        expect(fn () => Documents::dispatchCoalesced($document))->toThrow(TypeError::class);
        expect(Documents::dispatchCoalesced($old))->toBeFalse();
        Documents::invalidate($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned);
        DB::beginTransaction();
        Documents::restoreTarget($target);
        DB::rollBack();
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned);
        Documents::restoreTarget($target);
        $new = new RenderedDocument('new', $target, latestSnapshotWins: true);
        expect($new->snapshotId)->not->toBe($old->snapshotId)
            ->and(Documents::dispatchCoalesced($old))->toBeFalse();
    });
});

it('tombstones and retires all published versions atomically for cleanup', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/retired.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $first = Documents::publishWithResult(new RenderedDocument('first', $target, latestSnapshotWins: true));
        $snapshot = new RenderedDocument('second', $target, latestSnapshotWins: true);
        $second = Documents::publishWithResult($snapshot);
        $storage = new VersionedDocumentStorage;

        DB::beginTransaction();
        Documents::retireTarget($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned)
            ->and($storage->exists($context))->toBeFalse();
        DB::rollBack();
        expect($storage->get($context))->toBe('second')
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);

        Documents::retireTarget($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned)
            ->and($storage->versions($context))->toBe([])
            ->and(Documents::dispatchCoalesced($snapshot))->toBeFalse();
        Storage::disk('documents')->assertExists("invoices/retired.pdf/{$first->version}/document.pdf");
        Storage::disk('documents')->assertExists("invoices/retired.pdf/{$second->version}/document.pdf");
        Config::set('concurrency.default', 'sync');
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        Storage::disk('documents')->assertMissing("invoices/retired.pdf/{$first->version}");
        Storage::disk('documents')->assertMissing("invoices/retired.pdf/{$second->version}");
    });
});

it('rolls back the tombstone if retirement fails', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/retire-failure.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        Documents::publishWithResult(new RenderedDocument('current', $target, latestSnapshotWins: true));
        $storage = new class extends VersionedDocumentStorage
        {
            public function retire(DocumentStorageContext $context): void
            {
                parent::retire($context);

                throw new RuntimeException('Retirement failed');
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);

        expect(fn () => Documents::retireTarget($target))->toThrow(RuntimeException::class, 'Retirement failed')
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published)
            ->and($storage->get($context))->toBe('current');
    });
});

it('does not reuse a publication superseded during its existence check', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/reuse-race.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $rendered = new RenderedDocument('old', $target, latestSnapshotWins: true);
        Documents::publishWithResult($rendered);
        $storage = new class($target) extends VersionedDocumentStorage
        {
            public function __construct(private DocumentTarget $target)
            {
                parent::__construct();
            }

            public function exists(DocumentStorageContext $context, ?string $version = null): bool
            {
                $exists = parent::exists($context, $version);
                new RenderedDocument('new', $this->target, latestSnapshotWins: true);

                return $exists;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        expect(Documents::publishIfChanged($rendered)->status)->toBe(DocumentPublicationStatus::Superseded);
    });
});

it('reuses an unchanged synchronous PDF and regenerates when its version is missing', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/reuse.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $rendered = new RenderedDocument('same', $target, latestSnapshotWins: true);
        $first = Documents::publishIfChanged($rendered);
        $second = Documents::publishIfChanged(new RenderedDocument('same', $target, latestSnapshotWins: true));
        $storage = new VersionedDocumentStorage;
        expect($second->status)->toBe(DocumentPublicationStatus::Published)
            ->and($second->version)->toBe($first->version)
            ->and($storage->versions($context))->toHaveCount(1);
        Storage::disk('documents')->delete("invoices/reuse.pdf/{$first->version}/document.pdf");
        $third = Documents::publishIfChanged($rendered);
        expect($third->version)->not->toBe($first->version)
            ->and($storage->get($context))->toBe('same');
        new RenderedDocument('newer', $target, latestSnapshotWins: true);
        expect(Documents::publishIfChanged($rendered)->status)->toBe(DocumentPublicationStatus::Superseded);
    });
});

it('marks a synchronously published or reused snapshot complete without waiting for a worker', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/sync-status.pdf', 'documents'));
        $first = new RenderedDocument('first', $target, latestSnapshotWins: true);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        Documents::publishWithResult($first);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published)
            ->and(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeFalse();
        $same = new RenderedDocument('first', $target, latestSnapshotWins: true);
        Documents::publishIfChanged($same);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published)
            ->and(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeFalse();
    });
});

it('avoids a second publication when synchronous work finishes during queued generation', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        Event::fake([DocumentStored::class]);
        $context = new FilesystemDocumentStorageContext('invoices/concurrent-reuse.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $generator = new class extends DocumentGenerator
        {
            public function __construct() {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                Documents::publishWithResult(new GeneratedDocument($rendered, 'synchronous PDF', 'sync'));

                return 'queued PDF';
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        Documents::dispatchCoalesced(new RenderedDocument('same', $target, latestSnapshotWins: true));
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $storage = new VersionedDocumentStorage;
        expect($storage->get($context))->toBe('synchronous PDF')
            ->and($storage->versions($context))->toHaveCount(1);
        Event::assertDispatchedTimes(DocumentStored::class, 1);
    });
});

it('reuses the first queued publication when synchronous generation finishes later', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Event::fake([DocumentStored::class]);
        $context = new FilesystemDocumentStorageContext('invoices/first-queued.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $snapshot = new RenderedDocument('same', $target, latestSnapshotWins: true);
        $row = DB::table('document_coalescing_targets')->first();
        $generator = new class($row->target_key, $row->worker_token) extends DocumentGenerator
        {
            private int $calls = 0;

            public function __construct(private string $key, private string $token) {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                if (++$this->calls === 1) {
                    app(DocumentCoalescer::class)->work($this->key, $this->token);

                    return 'synchronous PDF';
                }

                return 'queued PDF';
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        $result = Documents::publishWithResult($snapshot);
        $storage = new VersionedDocumentStorage;
        expect($result->version)->toBe($storage->versions($context)[0])
            ->and($storage->get($context))->toBe('queued PDF')
            ->and($storage->versions($context))->toHaveCount(1);
        Event::assertDispatchedTimes(DocumentStored::class, 1);
    });
});

it('publishes again when the first matching version has lost its PDF', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Event::fake([DocumentStored::class]);
        $context = new FilesystemDocumentStorageContext('invoices/first-missing.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $snapshot = new RenderedDocument('same', $target, latestSnapshotWins: true);
        $first = Documents::publishWithResult(new GeneratedDocument($snapshot, 'first PDF', 'first'));
        Storage::disk('documents')->delete("invoices/first-missing.pdf/{$first->version}/document.pdf");
        $second = Documents::publishWithResult(new GeneratedDocument($snapshot, 'replacement PDF', 'second'));
        expect($second->version)->not->toBe($first->version)
            ->and((new VersionedDocumentStorage)->get($context))->toBe('replacement PDF');
        Event::assertDispatchedTimes(DocumentStored::class, 2);
    });
});

it('allows only one overlapping worker to publish the same pending snapshot', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Event::fake([DocumentStored::class]);
        $context = new FilesystemDocumentStorageContext('invoices/overlapping-workers.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        new RenderedDocument('same', $target, latestSnapshotWins: true);
        $row = DB::table('document_coalescing_targets')->first();
        $generator = new class($row->target_key, $row->worker_token) extends DocumentGenerator
        {
            private int $calls = 0;

            public function __construct(private string $key, private string $token) {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                if (++$this->calls === 1) {
                    app(DocumentCoalescer::class)->work($this->key, $this->token);

                    return 'first worker';
                }

                return 'second worker';
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        app(DocumentCoalescer::class)->work($row->target_key, $row->worker_token);
        $storage = new VersionedDocumentStorage;
        expect($storage->get($context))->toBe('second worker')
            ->and($storage->versions($context))->toHaveCount(1)
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
        Event::assertDispatchedTimes(DocumentStored::class, 1);
    });
});

it('abandons a worker that loses ownership without clearing the replacement work', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Event::fake([DocumentStored::class]);
        $context = new FilesystemDocumentStorageContext('invoices/replaced-worker.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        new RenderedDocument('same', $target, latestSnapshotWins: true);
        $row = DB::table('document_coalescing_targets')->first();
        $generator = new class extends DocumentGenerator
        {
            public function __construct() {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                DB::table('document_coalescing_targets')->update(['worker_token' => 'replacement']);

                return 'obsolete worker';
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        app(DocumentCoalescer::class)->work($row->target_key, $row->worker_token);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending)
            ->and(DB::table('document_coalescing_targets')->value('worker_token'))->toBe('replacement')
            ->and(DB::table('document_coalescing_stages')->count())->toBe(0);
        Event::assertNotDispatched(DocumentStored::class);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        app(DocumentCoalescer::class)->work($row->target_key, 'replacement');
        expect((new VersionedDocumentStorage)->get($context))->toBe('same');
        Event::assertDispatchedTimes(DocumentStored::class, 1);
    });
});

it('rolls back tombstones with the owning database transaction', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Bus::fake();
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/rollback.pdf', 'documents'));
        $rendered = new RenderedDocument('queued', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($rendered);
        DB::beginTransaction();
        Documents::tombstoneTarget($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned);
        DB::rollBack();
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending)
            ->and(Documents::dispatchCoalesced($rendered))->toBeTrue();
    });
});

it('makes the last published version current regardless of job creation order', function () {
    withRealVersionedStorage(function () {
        $context = new FilesystemDocumentStorageContext('invoices/order.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $firstSnapshot = new RenderedDocument('first-created', $target, generator: HtmlDocumentGenerator::class);
        $secondSnapshot = new RenderedDocument('second-created', $target, generator: HtmlDocumentGenerator::class);
        $first = (new GenerateVersionedDocument($firstSnapshot));
        $second = (new GenerateVersionedDocument($secondSnapshot));

        $second->handle();
        $first->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$second->version(), $first->version()])
            ->and($storage->get($context))->toBe('first-created')
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeFalse()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeTrue();
    });
});

it('coalesces queued snapshots and preserves newer synchronous publications', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/coalesced.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $a = new RenderedDocument('A', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($a);
        $b = new RenderedDocument('B', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($b);
        $c = new RenderedDocument('C', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($c);

        expect(DB::table('jobs')->count())->toBe(1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $storage = new VersionedDocumentStorage;
        expect($storage->get($context))->toBe('C')
            ->and($storage->versions($context))->toHaveCount(1);

        $older = new RenderedDocument('queued', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($older);
        $newer = new RenderedDocument('sync', $target, latestSnapshotWins: true);
        expect(Documents::publishWithResult($newer)->status)->toBe(DocumentPublicationStatus::Published);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect($storage->get($context))->toBe('sync')
            ->and($storage->versions($context))->toHaveCount(2);
    });
});

it('treats a first snapshot as changed, reuses matching output, and rejects late older work', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/snapshot-change.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $generator = new class extends DocumentGenerator
        {
            public int $calls = 0;

            public function __construct() {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                $this->calls++;

                return $rendered->html;
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        $first = new RenderedDocument('first', $target, latestSnapshotWins: true);
        expect(app(DocumentSnapshotCache::class)->changed($first))->toBeTrue();
        Documents::dispatchCoalesced($first);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $same = new RenderedDocument('first', $target, latestSnapshotWins: true);
        expect(app(DocumentSnapshotCache::class)->changed($same))->toBeFalse();
        Documents::dispatchCoalesced($same);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect($generator->calls)->toBe(1);

        $newer = new RenderedDocument('newer', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($newer);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect(Documents::dispatchCoalesced($first))->toBeFalse()
            ->and((new VersionedDocumentStorage)->get($context))->toBe('newer')
            ->and($generator->calls)->toBe(2)
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

it('processes the newest rendered snapshot even if it is never dispatched', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/render-before-dispatch.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($older);
        $newer = new RenderedDocument('newer', $target, latestSnapshotWins: true);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('newer')
            ->and(Documents::dispatchCoalesced($older))->toBeFalse()
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
    });
});

it('rolls back both ordering and pending content if snapshot capture fails', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/capture-rollback.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
        DB::unprepared("CREATE TRIGGER reject_snapshot_capture BEFORE UPDATE OF pending ON document_coalescing_targets BEGIN SELECT RAISE(FAIL, 'reject capture'); END");
        expect(fn () => new RenderedDocument('newer', $target, latestSnapshotWins: true))
            ->toThrow(QueryException::class, 'reject capture');
        DB::unprepared('DROP TRIGGER reject_snapshot_capture');
        expect(DB::table('document_coalescing_targets')->value('latest_snapshot'))->toBe($older->snapshotId);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('older');
    });
});

it('preserves equivalent and A to B to A ordering for rendered snapshot dispatches', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/atomic-aba.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $document = new class($target) extends Document
        {
            public static ?bool $latestSnapshotWins = true;

            public string $content = 'A';

            public function __construct(private DocumentTarget $target) {}

            public function html(): string
            {
                return $this->content;
            }

            public function target(): DocumentTarget
            {
                return $this->target;
            }

            public function path(): string
            {
                return 'unused';
            }
        };
        Documents::dispatchCoalesced($document->render());
        $firstId = DB::table('document_coalescing_targets')->value('latest_snapshot');
        Documents::dispatchCoalesced($document->render());
        expect(DB::table('document_coalescing_targets')->value('latest_snapshot'))->toBe($firstId);
        $document->content = 'B';
        Documents::dispatchCoalesced($document->render());
        $document->content = 'A';
        Documents::dispatchCoalesced($document->render());
        expect(DB::table('document_coalescing_targets')->value('latest_snapshot'))->not->toBe($firstId)
            ->and(DB::table('jobs')->count())->toBe(1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('A');
    });
});

it('invalidates queued and generated snapshots without reviving them after rollback', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/deleted.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $rendered = new RenderedDocument('old', $target, latestSnapshotWins: true);
        $generated = Documents::generate($rendered);
        Documents::dispatchCoalesced($rendered);
        DB::beginTransaction();
        Documents::invalidate($target);
        DB::rollBack();
        expect(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeTrue();

        Documents::invalidate($target);
        expect(Documents::publishWithResult($generated)->status)->toBe(DocumentPublicationStatus::Superseded);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->versions($context))->toBe([])
            ->and(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeFalse();
    });
});

it('deduplicates equivalent snapshots but assigns a new ID after returning to earlier content', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/aba.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $first = new RenderedDocument('A', $target, latestSnapshotWins: true);
        $equivalent = new RenderedDocument('A', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($first);
        Documents::dispatchCoalesced($equivalent);
        $second = new RenderedDocument('B', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($second);
        $last = new RenderedDocument('A', $target, latestSnapshotWins: true);
        expect(Documents::dispatchCoalesced($last))->toBeTrue()
            ->and(Documents::dispatchCoalesced($first))->toBeFalse();

        expect($equivalent->snapshotId)->toBe($first->snapshotId)
            ->and($last->snapshotId)->not->toBe($first->snapshotId)
            ->and(DB::table('jobs')->count())->toBe(1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('A');
    });
});

it('processes the newest snapshot requested while a worker is generating', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/during-generation.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $generator = new class($target) extends DocumentGenerator
        {
            public int $calls = 0;

            public function __construct(private DocumentTarget $target) {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                $this->calls++;

                if ($this->calls === 1) {
                    Documents::dispatchCoalesced(new RenderedDocument('B', $this->target, latestSnapshotWins: true));
                    Documents::dispatchCoalesced(new RenderedDocument('C', $this->target, latestSnapshotWins: true));
                }

                return $rendered->html;
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        Documents::dispatchCoalesced(new RenderedDocument('A', $target, latestSnapshotWins: true));

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('C')
            ->and($generator->calls)->toBe(2)
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

it('fences a staged publication when the target is tombstoned and restored', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/deletion-race.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $storage = new class($target) extends VersionedDocumentStorage
        {
            public function __construct(private DocumentTarget $target)
            {
                parent::__construct();
            }

            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);
                Documents::tombstoneTarget($this->target);
                Documents::restoreTarget($this->target);

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        $old = new RenderedDocument('old', $target, latestSnapshotWins: true);
        expect(Documents::publishWithResult($old)->status)->toBe(DocumentPublicationStatus::Superseded)
            ->and($storage->versions($context))->toBe([])
            ->and(DB::table('document_coalescing_stages')->count())->toBe(0);
    });
});

it('does not publish an older stage when a new snapshot registers during staging', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/stage-race.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $storage = new class($target) extends VersionedDocumentStorage
        {
            public function __construct(private DocumentTarget $target)
            {
                parent::__construct();
            }

            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);
                new RenderedDocument('newer', $this->target, latestSnapshotWins: true);

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);

        expect(Documents::publishWithResult($older)->status)->toBe(DocumentPublicationStatus::Superseded)
            ->and($storage->versions($context))->toBe([])
            ->and(DB::table('document_coalescing_stages')->count())->toBe(0)
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
    });
});

it('does not publish a stage invalidated while it was being written', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/deleted-during-stage.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $storage = new class($target) extends VersionedDocumentStorage
        {
            public function __construct(private DocumentTarget $target)
            {
                parent::__construct();
            }

            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);
                Documents::invalidate($this->target);

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        $rendered = new RenderedDocument('old', $target, latestSnapshotWins: true);

        expect(Documents::publishWithResult($rendered)->status)->toBe(DocumentPublicationStatus::Superseded)
            ->and($storage->versions($context))->toBe([]);
    });
});

it('does not let a staged older publication overwrite a newer publication from another process', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/concurrent.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $storage = new class($target) extends VersionedDocumentStorage
        {
            private bool $startedChild = false;

            public function __construct(private DocumentTarget $target)
            {
                parent::__construct();
            }

            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);

                if ($this->startedChild) {
                    return $stage;
                }
                $this->startedChild = true;
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork the concurrent publication test.');
                }

                if ($pid === 0) {
                    DB::purge('documents-integration');

                    try {
                        Documents::publish(new RenderedDocument('newer process', $this->target, latestSnapshotWins: true));
                        exit(0);
                    } catch (Throwable $exception) {
                        fwrite(STDERR, $exception->getMessage().PHP_EOL);
                        exit(1);
                    }
                }

                if (pcntl_waitpid($pid, $status) !== $pid || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    throw new RuntimeException('The concurrent publication process failed.');
                }

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        $older = new RenderedDocument('older process', $target, latestSnapshotWins: true);

        expect(Documents::publishWithResult($older)->status)->toBe(DocumentPublicationStatus::Superseded)
            ->and($storage->get($context))->toBe('newer process')
            ->and($storage->versions($context))->toHaveCount(1);
    });
});

it('rolls back the version switch when the ordered fingerprint update fails', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/transaction-rollback.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $first = new RenderedDocument('first', $target, latestSnapshotWins: true);
        Documents::publish($first);
        $second = new RenderedDocument('second', $target, latestSnapshotWins: true);
        DB::unprepared("CREATE TRIGGER reject_document_publication BEFORE UPDATE OF published_fingerprint ON document_coalescing_targets BEGIN SELECT RAISE(FAIL, 'reject publication'); END");

        expect(fn () => Documents::publish($second))->toThrow(QueryException::class, 'reject publication');
        $storage = new VersionedDocumentStorage;
        expect($storage->get($context))->toBe('first')
            ->and($storage->versions($context))->toHaveCount(1)
            ->and(DB::table('document_coalescing_stages')->count())->toBe(1)
            ->and(app(DocumentSnapshotCache::class)->changed($first))->toBeFalse();

        DB::unprepared('DROP TRIGGER reject_document_publication');
        DB::table('document_coalescing_stages')->update(['created_at' => now()->subHours(2)]);
        $this->artisan('documents:recover')->assertSuccessful();
        expect(DB::table('document_coalescing_stages')->count())->toBe(0);
    });
});

it('fails closed if a worker runs with coalescing disabled or bypasses staged publication', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/disabled.pdf', 'documents'));
        $rendered = new RenderedDocument('A', $target, latestSnapshotWins: true);
        $generated = new GeneratedDocument($rendered, 'A', 'generation');

        expect(fn () => app(DocumentSnapshotCache::class)->publish($generated, fn () => null))
            ->toThrow(LogicException::class, 'must use DocumentPublisher');
        Config::set('laravel-documents.coalescing.enabled', false);
        expect(fn () => app(DocumentCoalescer::class)->work('target', 'token'))
            ->toThrow(LogicException::class, 'must be enabled');
    });
});

it('keeps older version directories and their legacy hash files readable', function () {
    withRealVersionedStorage(function () {
        $context = new FilesystemDocumentStorageContext('invoices/legacy.pdf', 'documents');
        $legacy = VersionedStorage::directory($context->path, disk: $context->disk)->write(
            function ($files) {
                $files->put('document.pdf', 'old PDF');
                $files->put('document.hash', 'old hash');
            },
        );
        $target = new DocumentTarget('versioned', $context);
        $job = (new GenerateVersionedDocument(new RenderedDocument('new PDF', $target, generator: HtmlDocumentGenerator::class)));
        $job->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$legacy, $job->version()])
            ->and($storage->get($context, $legacy))->toBe('old PDF')
            ->and($storage->get($context))->toBe('new PDF')
            ->and(VersionedStorage::directory($context->path, disk: $context->disk)->get('document.hash', $legacy))->toBe('old hash');
    });
});
