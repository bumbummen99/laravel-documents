<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentTargetStatus;
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

it('rolls back a failed publication commit and leaves its stage recoverable', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/failed-publication-commit.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $snapshot = new RenderedDocument('same', $target, latestSnapshotWins: true);
        $storage = new class extends VersionedDocumentStorage
        {
            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);
                Event::listen(TransactionCommitting::class, static fn () => throw new RuntimeException('publication commit refused'));

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);

        try {
            expect(fn () => Documents::publishWithResult(new GeneratedDocument($snapshot, 'PDF', 'commit-test')))
                ->toThrow(RuntimeException::class, 'publication commit refused');
        } finally {
            Event::forget(TransactionCommitting::class);
        }
        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse()
            ->and($storage->versions($context))->toBe([])
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending)
            ->and(DB::table('document_coalescing_stages')->count())->toBe(1);
        DB::table('document_coalescing_stages')->update(['created_at' => now()->subHours(2)]);
        $this->artisan('documents:recover')->assertSuccessful();
        expect(DB::table('document_coalescing_stages')->count())->toBe(0);
    });
});

it('cleans abandoned storage uploads that were never recorded in the document journal', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('concurrency.default', 'sync');
        $handler = VersionedStorage::directory('invoices/unrecorded-upload.pdf', disk: 'documents');
        $stage = $handler->stage(fn (FilesystemAdapter $files) => $files->put('document.pdf', 'partial'));
        DB::table('versioned_storage_stages')->where('version', $stage->version)->update([
            'state' => 'uploading',
            'cleanup_after' => now()->subMinute(),
        ]);
        expect(DB::table('document_coalescing_stages')->count())->toBe(0);
        $this->artisan('documents:recover')->assertSuccessful();
        expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeTrue();
        $this->artisan('laravel-versioned-storage:cleanup')->assertSuccessful();
        expect(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
        Storage::disk('documents')->assertMissing("invoices/unrecorded-upload.pdf/{$stage->version}/document.pdf");
    });
});

it('reports shared target state across joined requests, recovery, publication, and deletion', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/status.pdf', 'documents'));
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::NotScheduled);
        $first = new RenderedDocument('first', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($first);
        Documents::dispatchCoalesced(new RenderedDocument('last', $target, latestSnapshotWins: true));
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute()]);
        app(DocumentCoalescer::class)->recover();
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
        new RenderedDocument('not queued', $target, latestSnapshotWins: true);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        Documents::tombstoneTarget($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Tombstoned);
        Documents::restoreTarget($target);
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::NotScheduled);
    });
});

it('continues recovering other targets and stages when one wakeup fails', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $bad = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/recovery-bad.pdf', 'documents'));
        $goodContext = new FilesystemDocumentStorageContext('invoices/recovery-good.pdf', 'documents');
        $good = new DocumentTarget('versioned', $goodContext);
        new RenderedDocument('bad', $bad, latestSnapshotWins: true);
        new RenderedDocument('good', $good, latestSnapshotWins: true);
        DB::table('document_coalescing_targets')->where('target_key', hash('sha256', $bad->context->identity()))
            ->update(['queue_connection' => 'missing']);
        $handler = VersionedStorage::directory('invoices/recovery-stage.pdf', disk: 'documents');
        $stage = $handler->stage(function (FilesystemAdapter $files, string $version): void {
            DB::table('document_coalescing_stages')->insert([
                'version' => $version, 'target_key' => str_repeat('a', 64), 'created_at' => now()->subHours(2),
            ]);
            $files->put('document.pdf', 'abandoned');
        });

        expect(fn () => app(DocumentCoalescer::class)->recover())->toThrow(InvalidArgumentException::class);
        expect(DB::table('document_coalescing_stages')->where('version', $stage->version)->exists())->toBeFalse()
            ->and(Documents::targetStatus($bad))->toBe(DocumentTargetStatus::Pending);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($goodContext))->toBe('good');
    });
});

it('continues reclaiming other stages after one stage fails', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $handler = VersionedStorage::directory('invoices/stage-recovery-isolation.pdf', disk: 'documents');
        $write = function (FilesystemAdapter $files, string $version): void {
            DB::table('document_coalescing_stages')->insert([
                'version' => $version, 'target_key' => str_repeat('a', 64), 'created_at' => now()->subHours(2),
            ]);
            $files->put('document.pdf', 'abandoned');
        };
        $bad = $handler->stage($write);
        $good = $handler->stage($write);
        DB::unprepared("CREATE TRIGGER reject_stage_recovery BEFORE UPDATE ON document_coalescing_stages WHEN OLD.version = '{$bad->version}' BEGIN SELECT RAISE(FAIL, 'reject stage'); END");

        expect(fn () => app(DocumentCoalescer::class)->recover())->toThrow(QueryException::class, 'reject stage');
        expect(DB::table('document_coalescing_stages')->where('version', $bad->version)->exists())->toBeTrue()
            ->and(DB::table('document_coalescing_stages')->where('version', $good->version)->exists())->toBeFalse();
        DB::unprepared('DROP TRIGGER reject_stage_recovery');
        app(DocumentCoalescer::class)->recover();
        expect(DB::table('document_coalescing_stages')->where('version', $bad->version)->exists())->toBeFalse();
    });
});

it('can journal a stage before it becomes ready and recover it after a publication rollback', function () {
    withRealVersionedStorage(function () {
        Schema::create('document_stage_journal_test', function (Blueprint $table): void {
            $table->string('version')->primary();
        });
        $handler = VersionedStorage::directory('invoices/staged.pdf', disk: 'documents');
        $stage = $handler->stage(function (FilesystemAdapter $files, string $version): void {
            expect(DB::table('versioned_storage_stages')->where('version', $version)->value('state'))->toBe('uploading');
            DB::table('document_stage_journal_test')->insert(['version' => $version]);
            expect($files->put('document.pdf', 'staged PDF'))->toBeTrue();
        });

        expect(DB::table('document_stage_journal_test')->value('version'))->toBe($stage->version)
            ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->value('state'))->toBe('ready');

        DB::beginTransaction();
        $stage->publish();
        DB::rollBack();

        expect($handler->versions())->toBe([])
            ->and(DB::table('document_stage_journal_test')->value('version'))->toBe($stage->version);

        VersionedStorage::staged($stage->version)->publish();

        expect($handler->get('document.pdf'))->toBe('staged PDF');
    });
});

it('recovers the latest snapshot rendered before any explicit dispatch', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/render-crash.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
        new RenderedDocument('newer', $target, latestSnapshotWins: true);

        expect(Documents::dispatchCoalesced($older))->toBeFalse()
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('newer')
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
    });
});

it('recovers a synchronous render without publishing an older generated snapshot', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/synchronous-recovery.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
        $generated = Documents::generate($older);
        new RenderedDocument('newer', $target, latestSnapshotWins: true);
        expect(Documents::publishWithResult($generated)->status)->toBe(DocumentPublicationStatus::Superseded);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('newer');
    });
});

it('rejects a stale generated snapshot and can recover an undispatched request', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/recovery.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('old', $target, latestSnapshotWins: true);
        $generated = Documents::generate($older);
        $newer = new RenderedDocument('new', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($newer);

        expect(Documents::publishWithResult($generated)->status)->toBe(DocumentPublicationStatus::Superseded);
        DB::table('jobs')->delete();
        DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute()]);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('new');
    });
});

it('processes the oldest queued wakeup after repeated recovery during a long backlog', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/long-backlog.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        Documents::dispatchCoalesced(new RenderedDocument('newest', $target, latestSnapshotWins: true));

        for ($i = 0; $i < 3; $i++) {
            DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute()]);
            $this->artisan('documents:recover')->assertSuccessful();
        }

        expect(DB::table('jobs')->count())->toBe(4);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('newest')
            ->and(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeFalse();
    });
});

it('preserves pending work when snapshot dispatch fails and recovers after retry', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/atomic-capture.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $older = new RenderedDocument('older', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($older);
        $document = new class($target) extends Document
        {
            public static ?bool $latestSnapshotWins = true;

            public function __construct(private DocumentTarget $target) {}

            public function html(): string
            {
                return 'newer';
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
        $newer = $document->render();
        $previousPending = DB::table('document_coalescing_targets')->value('pending');
        DB::unprepared("CREATE TRIGGER reject_document_capture BEFORE UPDATE OF pending ON document_coalescing_targets BEGIN SELECT RAISE(FAIL, 'reject capture'); END");
        expect(fn () => Documents::dispatchCoalesced($newer))
            ->toThrow(QueryException::class, 'reject capture');
        expect(DB::table('document_coalescing_targets')->value('latest_snapshot'))->toBe($newer->snapshotId)
            ->and(DB::table('document_coalescing_targets')->value('pending'))->toBe($previousPending);
        DB::unprepared('DROP TRIGGER reject_document_capture');

        expect(Documents::dispatchCoalesced($newer))->toBeTrue();
        DB::table('jobs')->delete();
        DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute()]);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('newer');
    });
});

it('recovers terminal worker failures and regenerates a missing current PDF', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/failed-worker.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $generator = new class extends DocumentGenerator
        {
            public function __construct() {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                throw new RuntimeException('PDF renderer unavailable');
            }
        };
        $this->app->instance(DocumentGenerator::class, $generator);
        $rendered = new RenderedDocument('retry', $target, latestSnapshotWins: true);
        Documents::dispatchCoalesced($rendered);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        expect(DB::table('document_coalescing_targets')->whereNotNull('pending')->exists())->toBeTrue()
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);

        $this->app->instance(DocumentGenerator::class, $this->app->make(HtmlDocumentGenerator::class));
        DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute()]);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
        $storage = new VersionedDocumentStorage;
        $version = $storage->versions($context)[0];
        Storage::disk('documents')->delete("invoices/failed-worker.pdf/{$version}/document.pdf");

        Documents::dispatchCoalesced($rendered);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect($storage->get($context))->toBe('retry')
            ->and($storage->versions($context))->toHaveCount(2);
    });
});

it('fails rather than publishing a stage reclaimed while its publisher was delayed', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/reclaimed.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $storage = new class extends VersionedDocumentStorage
        {
            public function stage(DocumentStorageContext $context, string $contents, Closure $record): StagedVersion
            {
                $stage = parent::stage($context, $contents, $record);
                DB::table('document_coalescing_stages')->where('version', $stage->version)->update(['created_at' => now()->subHours(2)]);
                app(DocumentCoalescer::class)->recover();

                return $stage;
            }
        };
        app(DocumentStorageManager::class)->register('versioned', fn () => $storage, GenerateVersionedDocument::class);
        $rendered = new RenderedDocument('old', $target, latestSnapshotWins: true);

        expect(fn () => Documents::publishWithResult($rendered))
            ->toThrow(RuntimeException::class, 'reclaimed before publication')
            ->and($storage->versions($context))->toBe([]);
    });
});

it('recovers a request after dispatch fails and discards abandoned ready stages', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/lost-dispatch.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $rendered = new RenderedDocument('recover', $target, latestSnapshotWins: true);

        expect(fn () => Documents::dispatchCoalesced($rendered, 'missing'))->toThrow(InvalidArgumentException::class);
        expect(DB::table('document_coalescing_targets')->whereNotNull('pending')->count())->toBe(1);
        DB::table('document_coalescing_targets')->update(['lease_until' => now()->subMinute(), 'queue_connection' => 'database']);
        $handler = VersionedStorage::directory('invoices/abandoned.pdf', disk: 'documents');
        $stage = $handler->stage(function (FilesystemAdapter $files, string $version): void {
            DB::table('document_coalescing_stages')->insert([
                'version' => $version, 'target_key' => str_repeat('a', 64), 'created_at' => now()->subHours(2),
            ]);
            $files->put('document.pdf', 'abandoned');
        });

        $this->artisan('documents:recover')->assertSuccessful();
        expect(DB::table('document_coalescing_stages')->where('version', $stage->version)->exists())->toBeFalse()
            ->and(DB::table('versioned_storage_stages')->where('version', $stage->version)->exists())->toBeFalse();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('recover');
    });
});
