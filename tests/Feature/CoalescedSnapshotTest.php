<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentMetadata;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentTargetStatus;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\FakeDocumentGenerator;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\HtmlDocumentGenerator;
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

it('conditionally captures without replacing a newer registered snapshot', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $document = new class extends Document
        {
            public static string $storage = 'versioned';

            public static ?string $path = 'invoices/conditional.pdf';

            public static ?string $disk = 'documents';

            public static ?bool $latestSnapshotWins = true;

            public ?Closure $duringHtml = null;

            public function html(): string
            {
                ($this->duringHtml)?->__invoke();

                return 'old';
            }
        };
        $initial = $document->render();
        $document->duringHtml = fn () => new RenderedDocument('new', $document->target(), latestSnapshotWins: true);

        $result = app(DocumentCoalescer::class)->captureIfUnchanged($document->target(), fn () => $document);

        expect($result)->toBeNull()
            ->and(Documents::targetStatus($document->target()))->toBe(DocumentTargetStatus::Pending);
        $row = DB::table('document_coalescing_targets')->first();
        expect($row->latest_snapshot)->not->toBe($initial->snapshotId)
            ->and($row->latest_fingerprint)->toBe(RenderedDocument::fingerprintFor('new', $document->target(), $document->pdfDriver()));
    });
});

it('inspects an unchanged publication without registering pending work', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $document = new class extends Document
        {
            public static string $storage = 'versioned';

            public static ?string $path = 'invoices/inspect.pdf';

            public static ?string $disk = 'documents';

            public static ?bool $latestSnapshotWins = true;

            public function html(): string
            {
                return 'same';
            }

            public function generator(): string
            {
                return HtmlDocumentGenerator::class;
            }
        };
        $published = Documents::publishIfChanged($document);
        $coalescer = app(DocumentCoalescer::class);
        $captured = $coalescer->captureIfUnchanged($document->target(), fn () => $document);

        expect($captured)->not->toBeNull()
            ->and($coalescer->publishedVersion($captured))->toBe($published->version)
            ->and($coalescer->isCurrent($captured))->toBeTrue()
            ->and(Documents::targetStatus($document->target()))->toBe(DocumentTargetStatus::Published)
            ->and(DB::table('document_coalescing_targets')->first()->pending)->toBeNull();

        new RenderedDocument('new', $document->target(), latestSnapshotWins: true);
        expect($coalescer->publishedVersion($captured))->toBeNull()
            ->and($coalescer->isCurrent($captured))->toBeFalse();
    });
});

it('recovers the generator captured with the snapshot before dispatch', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/custom-generator.pdf', 'documents');
        $target = new DocumentTarget('versioned', $context);
        $default = new RenderedDocument('same', $target, latestSnapshotWins: true);
        $snapshot = new RenderedDocument('same', $target, latestSnapshotWins: true, generator: FakeDocumentGenerator::class);
        expect($snapshot->snapshotId)->not->toBe($default->snapshotId)
            ->and(unserialize(serialize($snapshot))->generatorClass())->toBe(FakeDocumentGenerator::class)
            ->and(serialize($snapshot))->not->toContain('O:'.strlen(FakeDocumentGenerator::class).':"'.FakeDocumentGenerator::class.'"');
        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('rendered PDF');
        expect(Documents::publishWithResult($snapshot)->version)
            ->toBe((new VersionedDocumentStorage)->versions($context)[0]);
    });
});

it('resolves the captured generator binding once per coalesced generation', function (string $path, string $generatorClass) {
    withRealVersionedStorage(function () use ($path, $generatorClass) {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $resolutions = 0;
        app()->bind($generatorClass, function () use (&$resolutions): DocumentGenerator {
            $resolutions++;

            return app(HtmlDocumentGenerator::class);
        });
        $context = new FilesystemDocumentStorageContext('invoices/resolution.pdf', 'documents');
        $snapshot = new RenderedDocument('captured bytes', new DocumentTarget('versioned', $context), latestSnapshotWins: true, generator: $generatorClass);

        expect($resolutions)->toBe(0);

        if ($path === 'direct') {
            Documents::publish(DocumentGenerator::generate($snapshot));
        } elseif ($path === 'workflow') {
            Documents::publish($snapshot);
        } elseif ($path === 'job') {
            Documents::makeJob($snapshot)->handle();
        } else {
            $this->artisan('documents:recover')->assertSuccessful();
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        }

        expect($resolutions)->toBe(1)
            ->and((new VersionedDocumentStorage)->get($context))->toBe('captured bytes')
            ->and(Documents::targetStatus($snapshot->target))->toBe(DocumentTargetStatus::Published);
    });
})->with(['direct', 'workflow', 'job', 'recovery'])
    ->with([DocumentGenerator::class, FakeDocumentGenerator::class]);

it('rejects invalid snapshot generators without recording pending work', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $target = new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/invalid-generator.pdf', 'documents'));
        expect(fn () => new RenderedDocument('same', $target, latestSnapshotWins: true, generator: stdClass::class))
            ->toThrow(InvalidArgumentException::class, 'generator')
            ->and(DB::table('document_coalescing_targets')->count())->toBe(0);
    });
});

it('uses the document generator for synchronous publication and coalesced jobs', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $document = new class extends Document
        {
            public static string $storage = 'versioned';

            public static ?string $path = 'invoices/custom-synchronous.pdf';

            public static ?string $disk = 'documents';

            public static ?bool $latestSnapshotWins = true;

            public function html(): string
            {
                return 'same';
            }

            public function generator(): string
            {
                return FakeDocumentGenerator::class;
            }
        };
        $snapshot = $document->render();
        expect($snapshot->generatorClass())->toBe(FakeDocumentGenerator::class)
            ->and(Documents::generate($snapshot)->contents)->toBe('rendered PDF');
        $job = Documents::makeJob($snapshot);
        $job->handle();
        expect($document->get())->toBe('rendered PDF')
            ->and(Documents::targetStatus($document->target()))->toBe(DocumentTargetStatus::Published);
    });
});

it('uses the captured generator for direct generation before recovery can reuse the publication', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $context = new FilesystemDocumentStorageContext('invoices/direct-generator.pdf', 'documents');
        $snapshot = new RenderedDocument('wrong generator output', new DocumentTarget('versioned', $context), latestSnapshotWins: true, generator: FakeDocumentGenerator::class);
        Event::fake([DocumentRendering::class, DocumentStored::class]);

        $generated = app(HtmlDocumentGenerator::class)->generate($snapshot, 'direct-generation');
        $result = Documents::publishWithResult($generated);

        expect($generated->contents)->toBe('rendered PDF')
            ->and($generated->generationId)->toBe('direct-generation')
            ->and((new VersionedDocumentStorage)->get($context))->toBe('rendered PDF')
            ->and(Documents::targetStatus($snapshot->target))->toBe(DocumentTargetStatus::Published)
            ->and($result->version)->not->toBeNull();
        Event::assertDispatchedOnce(DocumentRendering::class);
        Event::assertDispatchedOnce(DocumentStored::class);
    });
});

it('uses the container binding for the captured generator on direct calls', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(FakeDocumentGenerator::class, HtmlDocumentGenerator::class);
        $snapshot = new RenderedDocument('bound generator output', new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/bound-generator.pdf', 'documents')), latestSnapshotWins: true, generator: FakeDocumentGenerator::class);

        $generated = app(DocumentGenerator::class)->generate($snapshot);

        expect($generated->contents)->toBe('bound generator output')
            ->and($generated->fingerprint)->toBe($snapshot->fingerprint());
    });
});

it('uses the default generator binding for direct calls to a different generator', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $this->app->bind(DocumentGenerator::class, FakeDocumentGenerator::class);
        $snapshot = new RenderedDocument('wrong generator output', new DocumentTarget('versioned', new FilesystemDocumentStorageContext('invoices/default-generator.pdf', 'documents')), latestSnapshotWins: true);

        $generated = app(HtmlDocumentGenerator::class)->generate($snapshot);

        expect($generated->contents)->toBe('rendered PDF');
    });
});

it('uses the captured generator despite a legacy job generator override', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        Config::set('queue.default', 'database');
        $context = new FilesystemDocumentStorageContext('invoices/overridden-generator.pdf', 'documents');
        $snapshot = new RenderedDocument('different', new DocumentTarget('versioned', $context), latestSnapshotWins: true, generator: FakeDocumentGenerator::class);
        $job = new class($snapshot) extends GenerateVersionedDocument
        {
            protected function generator(): DocumentGenerator
            {
                return app(HtmlDocumentGenerator::class);
            }
        };
        Event::fake([DocumentStored::class]);

        $job->handle();
        Event::assertDispatchedOnce(DocumentStored::class);
        expect((new VersionedDocumentStorage)->get($context))->toBe('rendered PDF')
            ->and(Documents::targetStatus($snapshot->target))->toBe(DocumentTargetStatus::Published);

        $this->artisan('documents:recover')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        expect((new VersionedDocumentStorage)->get($context))->toBe('rendered PDF');
    });
});

it('uses the captured generator despite an unrelated job generator field in coalescing', function () {
    withRealVersionedStorage(function () {
        createCoalescingTables();
        Config::set('laravel-documents.coalescing.enabled', true);
        $context = new FilesystemDocumentStorageContext('invoices/changed-generator.pdf', 'documents');
        $snapshot = new RenderedDocument('different', new DocumentTarget('versioned', $context), latestSnapshotWins: true, generator: FakeDocumentGenerator::class);
        $job = new class($snapshot) extends GenerateVersionedDocument
        {
            protected string $generatorClass = HtmlDocumentGenerator::class;
        };

        $job->handle();
        expect((new VersionedDocumentStorage)->get($context))->toBe('rendered PDF')
            ->and(Documents::targetStatus($snapshot->target))->toBe(DocumentTargetStatus::Published);
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
