<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mateffy\JobProgress\LaravelJobProgressServiceProvider;
use Mateffy\JobProgress\Traits\Progress;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentMetadata;
use SkyRaptor\LaravelDocuments\DocumentPublicationStatus;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentTargetStatus;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateVersionedDocumentWithProgress as TrackedVersionedDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueueTables;
use SkyRaptor\LaravelVersionedStorage\StagedVersion;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageServiceProvider;

function withRealVersionedStorage(Closure $test): void
{
    $database = tempnam(sys_get_temp_dir(), 'documents-batch-');

    if ($database === false) {
        throw new RuntimeException('Unable to create the versioned storage test database.');
    }

    try {
        Config::set('database.connections.documents-integration', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'documents-integration');
        Config::set('laravel-versioned-storage.connection', 'documents-integration');
        Config::set('queue.batching.database', 'documents-integration');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'documents-integration',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        Config::set('queue.failed.driver', 'null');

        $packageRoot = dirname((new ReflectionClass(VersionedStorage::class))->getFileName(), 2);
        $migrations = glob($packageRoot.'/database/migrations/*.php');

        if ($migrations === false || $migrations === []) {
            throw new RuntimeException('Versioned storage migrations could not be found.');
        }

        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        QueueTables::createBatches();
        QueueTables::createJobs();

        $test();
    } finally {
        DB::disconnect('documents-integration');
        unlink($database);
    }
}

function createCoalescingTables(): void
{
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_27_105147_create_document_coalescing_tables.php')->up();
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_28_100710_add_tombstone_to_document_coalescing_targets.php')->up();
}

beforeEach(function () {
    if (! trait_exists(Progress::class) || ! class_exists(VersionedStorage::class)
        || ! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! extension_loaded('sockets')) {
        $this->markTestSkipped('Install both optional packages and their Unix extensions to run the versioned integration tests.');
    }

    $this->app->register(LaravelJobProgressServiceProvider::class);
    $this->app->register(VersionedStorageServiceProvider::class);
    $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    $this->app->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    Config::set('job-progress.cache.store', 'array');
    Storage::fake('documents');
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

it('encrypts pending snapshots and recovers legacy plaintext snapshots', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/encrypted-pending.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $rendered = new RenderedDocument('private HTML', $target, latestSnapshotWins: true);
        $stored = DB::table('document_coalescing_targets')->value('pending');
        expect($stored)->not->toContain('private HTML')
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        $legacy = json_encode([
            'html' => 'private HTML', 'storage' => 'versioned', 'path' => $context->path,
            'disk' => $context->disk, 'driver' => 'default', 'snapshot' => $rendered->snapshotId,
        ], JSON_THROW_ON_ERROR);
        DB::table('document_coalescing_targets')->update(['pending' => $legacy]);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('private HTML');
    });
});

it('recovers encrypted snapshots after key rotation and rejects invalid ciphertext', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->bind(DocumentGenerator::class, HtmlDocumentGenerator::class);
        $context = new FilesystemDocumentStorageContext('invoices/rotated-key.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        new RenderedDocument('protected HTML', $target, latestSnapshotWins: true);
        $pending = DB::table('document_coalescing_targets')->value('pending');
        $oldKey = Config::get('app.key');
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        expect(fn () => Documents::targetStatus($target))->toThrow(DecryptException::class)
            ->and(DB::table('document_coalescing_targets')->value('pending'))->toBe($pending);
        Config::set('app.key', null);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        expect(fn () => Documents::targetStatus($target))->toThrow(MissingAppKeyException::class)
            ->and(DB::table('document_coalescing_targets')->value('pending'))->toBe($pending);
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        Config::set('app.previous_keys', [$oldKey]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        expect(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Pending);
        DB::table('document_coalescing_targets')->update(['pending' => 'encrypted:v1:invalid']);
        expect(fn () => Documents::targetStatus($target))->toThrow(DecryptException::class);
        DB::table('document_coalescing_targets')->update(['pending' => $pending]);
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('protected HTML');
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

it('retains document metadata in encrypted snapshots and treats changes as new work', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $this->app->instance(DocumentGenerator::class, new class extends DocumentGenerator
        {
            public function __construct() {}

            protected function generateContents(RenderedDocument $rendered): string
            {
                return $rendered->html.':'.$rendered->metadata?->title;
            }
        });
        $context = new FilesystemDocumentStorageContext('invoices/metadata.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $first = new RenderedDocument('same', $target, latestSnapshotWins: true, metadata: new DocumentMetadata(title: 'private first'));
        $latest = new RenderedDocument('same', $target, latestSnapshotWins: true, metadata: new DocumentMetadata(title: 'private second'));
        expect($latest->snapshotId)->not->toBe($first->snapshotId)
            ->and(DB::table('document_coalescing_targets')->value('pending'))->not->toContain('private second');
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('same:private second')
            ->and(Documents::targetStatus($target))->toBe(DocumentTargetStatus::Published);
    });
});

it('rejects malformed metadata in a pending snapshot without clearing it', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $context = new FilesystemDocumentStorageContext('invoices/invalid-metadata.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        new RenderedDocument('same', $target, latestSnapshotWins: true, metadata: new DocumentMetadata(title: 'title'));
        $stored = DB::table('document_coalescing_targets')->value('pending');
        $payload = json_decode(Crypt::decryptString(substr($stored, strlen('encrypted:v1:'))), true, flags: JSON_THROW_ON_ERROR);
        $payload['metadata']['title'] = ['invalid'];
        $invalid = 'encrypted:v1:'.Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        DB::table('document_coalescing_targets')->update(['pending' => $invalid]);
        expect(fn () => Documents::targetStatus($target))->toThrow(InvalidArgumentException::class, 'metadata is invalid')
            ->and(DB::table('document_coalescing_targets')->value('pending'))->toBe($invalid);
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

it('tracks queued batch jobs while storing real document versions', function () {
    withRealVersionedStorage(function () {
        Event::fake([DocumentStored::class]);
        $failure = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failure): void {
            $failure = $event->exception;
        });
        Config::set('queue.default', 'database');
        $storage = new VersionedDocumentStorage;
        $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $firstSnapshot = new RenderedDocument('first', $target);
        $secondSnapshot = new RenderedDocument('second', $target);
        $first = (new TrackedVersionedDocument($firstSnapshot))->withGenerator(FakeDocumentGenerator::class);
        $second = (new TrackedVersionedDocument($secondSnapshot))->withGenerator(FakeDocumentGenerator::class);
        $firstId = $first->generationId();
        $secondId = $second->generationId();

        $batch = Bus::batch([$first, $second])->dispatch();

        expect(Bus::findBatch($batch->id)->progress())->toBe(0);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        if ($failure !== null) {
            throw $failure;
        }

        expect(Bus::findBatch($batch->id)->failedJobs)->toBe(0)
            ->and(Bus::findBatch($batch->id)->progress())->toBe(50)
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeFalse()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeTrue();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        if ($failure !== null) {
            throw $failure;
        }

        $firstProgress = TrackedVersionedDocument::getProgress($firstId);
        $secondProgress = TrackedVersionedDocument::getProgress($secondId);
        $versions = $storage->versions($context);

        expect(Bus::findBatch($batch->id)->progress())->toBe(100)
            ->and(Bus::findBatch($batch->id)->failedJobs)->toBe(0)
            ->and($firstProgress?->status->isCompleted())->toBeTrue()
            ->and($secondProgress?->status->isCompleted())->toBeTrue()
            ->and($firstProgress?->result)->toBeString()->not->toBeEmpty()
            ->and($secondProgress?->result)->toBeString()->not->toBeEmpty()
            ->and($firstProgress->result)->not->toBe($secondProgress->result)
            ->and($versions)->toBe([$firstProgress->result, $secondProgress->result])
            ->and($storage->get($context, $versions[0]))->toBe('rendered PDF')
            ->and($storage->get($context, $versions[1]))->toBe('rendered PDF')
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeTrue()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeFalse();

        Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $firstId
            && $event->version === $firstProgress->result);
        Event::assertDispatched(DocumentStored::class, fn (DocumentStored $event) => $event->generationId === $secondId
            && $event->version === $secondProgress->result);
    });
});

it('makes the last published version current regardless of job creation order', function () {
    withRealVersionedStorage(function () {
        $context = new FilesystemDocumentStorageContext('invoices/order.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $firstSnapshot = new RenderedDocument('first-created', $target);
        $secondSnapshot = new RenderedDocument('second-created', $target);
        $first = (new GenerateVersionedDocument($firstSnapshot))->withGenerator(HtmlDocumentGenerator::class);
        $second = (new GenerateVersionedDocument($secondSnapshot))->withGenerator(HtmlDocumentGenerator::class);

        $second->handle();
        $first->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$second->version(), $first->version()])
            ->and($storage->get($context))->toBe('first-created')
            ->and(app(DocumentSnapshotCache::class)->changed($firstSnapshot))->toBeFalse()
            ->and(app(DocumentSnapshotCache::class)->changed($secondSnapshot))->toBeTrue();
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

it('recovers terminal worker failures and regenerates a missing current PDF', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/failed-worker.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $generator = Mockery::mock(DocumentGenerator::class);
        $generator->shouldReceive('generate')->andThrow(new RuntimeException('PDF renderer unavailable'));
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

it('rejects unsupported storage, active transactions, and different metadata connections', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $versioned = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/guard.pdf', 'documents'));
        $disk = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/guard.pdf', 'documents'));

        expect(fn () => new RenderedDocument('A', $disk, latestSnapshotWins: true))
            ->toThrow(LogicException::class, 'requires versioned document storage');
        DB::beginTransaction();

        try {
            expect(fn () => new RenderedDocument('A', $versioned, latestSnapshotWins: true))
                ->toThrow(LogicException::class, 'outside a database transaction');
        } finally {
            DB::rollBack();
        }
        Config::set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('laravel-documents.coalescing.connection', 'other');
        expect(fn () => new RenderedDocument('A', $versioned, latestSnapshotWins: true))
            ->toThrow(LogicException::class, 'same database connection');
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
        $job = (new GenerateVersionedDocument(new RenderedDocument('new PDF', $target)))->withGenerator(HtmlDocumentGenerator::class);
        $job->handle();
        $storage = new VersionedDocumentStorage;

        expect($storage->versions($context))->toBe([$legacy, $job->version()])
            ->and($storage->get($context, $legacy))->toBe('old PDF')
            ->and($storage->get($context))->toBe('new PDF')
            ->and(VersionedStorage::directory($context->path, disk: $context->disk)->get('document.hash', $legacy))->toBe('old hash');
    });
});

it('rejects synchronous database-backed batches with real versioned storage', function () {
    withRealVersionedStorage(function () {
        Config::set('queue.default', 'sync');
        $storage = new VersionedDocumentStorage;
        $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $job = (new TrackedVersionedDocument(new RenderedDocument('HTML', $target)))->withGenerator(FakeDocumentGenerator::class);

        expect(fn () => Bus::batch([$job])->dispatch())
            ->toThrow(RuntimeException::class, 'Uploads cannot run inside a metadata transaction.');

        expect($storage->versions($context))->toBe([]);
    });
});
