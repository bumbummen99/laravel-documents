<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentMetadata;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use Spatie\LaravelPdf\Drivers\DomPdfDriver;
use Spatie\LaravelPdf\Encryption\PdfEncrypter;
use Spatie\LaravelPdf\Encryption\PdfEncryption;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\PdfServiceProvider;

beforeEach(function () {
    if (! class_exists(Dompdf::class)) {
        $this->markTestSkipped('Install dompdf/dompdf to test a real PDF backend.');
    }

    Storage::fake('documents');
    Config::set('laravel-pdf.driver', 'dompdf');
    $this->app->register(PdfServiceProvider::class);
});

it('generates and stores a PDF through the configured Spatie default driver', function () {
    $document = new class extends Document
    {
        public static ?string $disk = 'documents';

        public function path(): string
        {
            return 'invoices/real.pdf';
        }

        public function html(): string
        {
            return '<html><body><h1>Invoice</h1></body></html>';
        }
    };

    $rendered = $document->render();
    $job = unserialize(serialize(app(DocumentJobFactory::class)->make($rendered)));
    $job->handle();
    $pdf = $document->get();

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(500)
        ->and($document->exists())->toBeTrue()
        ->and(app(DocumentSnapshotCache::class)->changed($rendered))->toBeFalse();
});

it('applies builder metadata before returning PDF bytes without storing them', function () {
    app(DocumentPdfDriverManager::class)->register(
        'metadata',
        fn () => new DomPdfDriver,
        fn (PdfBuilder $builder) => $builder->meta(title: 'Invoice metadata', author: 'Accounts'),
    );
    $document = new class extends Document
    {
        public static ?string $disk = 'documents';

        public static ?string $path = 'invoices/metadata.pdf';

        public static string $pdfDriver = 'metadata';

        public function html(): string
        {
            return '<html><body>Invoice</body></html>';
        }
    };

    $pdf = app(DocumentGenerator::class)->generate($document->render())->contents;

    expect($pdf)->toStartWith('%PDF-')
        ->toContain('/Title (Invoice metadata)', '/Author (Accounts)')
        ->and($document->exists())->toBeFalse();
});

it('freezes document metadata in the snapshot and uses it instead of builder metadata', function () {
    app(DocumentPdfDriverManager::class)->register(
        'metadata-snapshot',
        fn () => new DomPdfDriver,
        fn (PdfBuilder $builder) => $builder->meta(title: 'Builder title', author: 'Builder author'),
    );
    $document = new class extends Document
    {
        public static string $pdfDriver = 'metadata-snapshot';

        public static ?string $path = 'invoices/metadata-snapshot.pdf';

        public string $title = 'Original title';

        public function html(): string
        {
            return '<html><body>Same HTML</body></html>';
        }

        public function metadata(): ?DocumentMetadata
        {
            return new DocumentMetadata(
                title: $this->title,
                author: 'Original author',
                creationDate: new DateTimeImmutable('2025-01-02T03:04:05+00:00'),
            );
        }
    };

    $snapshot = unserialize(serialize($document->render()));
    $document->title = 'Changed title';
    $newSnapshot = $document->render();
    $pdf = app(DocumentGenerator::class)->generate($snapshot)->contents;

    expect($pdf)->toContain('/Title (Original title)', '/Author (Original author)', "/CreationDate (D:20250102030405+00'00')")
        ->not->toContain('/Title (Builder title)', '/Title (Changed title)')
        ->and($snapshot->fingerprint())->not->toBe($newSnapshot->fingerprint());
});

it('includes each document metadata field in snapshot identity', function (string $field) {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('metadata.pdf', 'documents'));
    $empty = new RenderedDocument('same', $target, metadata: new DocumentMetadata);
    $changed = new RenderedDocument('same', $target, metadata: new DocumentMetadata(...[$field => 'changed']));

    expect($changed->fingerprint())->not->toBe($empty->fingerprint());
})->with(['title', 'author', 'subject', 'keywords', 'creator', 'creationDate']);

it('post-processes once and publishes the generated bytes', function (string $mode) {
    app(DocumentPdfDriverManager::class)->register(
        'encrypted',
        fn () => new DomPdfDriver,
        fn (PdfBuilder $builder) => $builder
            ->meta(title: 'Processed invoice')
            ->encrypt(userPassword: 'test-password'),
    );
    $processed = null;
    $encrypter = Mockery::mock(PdfEncrypter::class);
    $encrypter->shouldReceive('encrypt')->once()->andReturnUsing(
        function (string $pdf, PdfEncryption $encryption) use (&$processed): string {
            expect($pdf)->toContain('/Title (Processed invoice)')
                ->and($encryption->userPassword)->toBe('test-password');

            return $processed = $pdf."\n% test-encrypter-output";
        },
    );
    app()->instance(PdfEncrypter::class, $encrypter);
    $document = new class extends Document
    {
        public static ?string $disk = 'documents';

        public static ?string $path = 'invoices/processed.pdf';

        public static string $pdfDriver = 'encrypted';

        public function html(): string
        {
            return '<html><body>Invoice</body></html>';
        }
    };

    if ($mode === 'job') {
        $job = unserialize(serialize(app(DocumentJobFactory::class)->make($document->render())));
        $job->handle();
    } else {
        $generated = Documents::generate($document);
        expect($generated->contents)->toBe($processed);
        Documents::publish($generated);
    }

    expect($processed)->not->toBeNull()
        ->and($document->get())->toBe($processed);
})->with(['job', 'staged facade']);

it('propagates post-processing failures without storing an unprocessed PDF', function () {
    app(DocumentPdfDriverManager::class)->register(
        'failing-encryption',
        fn () => new DomPdfDriver,
        fn (PdfBuilder $builder) => $builder->encrypt(userPassword: 'test-password'),
    );
    $encrypter = Mockery::mock(PdfEncrypter::class);
    $encrypter->shouldReceive('encrypt')->once()->andThrow(new RuntimeException('Encryption failed.'));
    app()->instance(PdfEncrypter::class, $encrypter);
    $document = new class extends Document
    {
        public static ?string $disk = 'documents';

        public static ?string $path = 'invoices/failed-encryption.pdf';

        public static string $pdfDriver = 'failing-encryption';

        public function html(): string
        {
            return '<html><body>Invoice</body></html>';
        }
    };
    $job = app(DocumentJobFactory::class)->make($document->render());

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Encryption failed.')
        ->and($job->generated())->toBeFalse()
        ->and($document->exists())->toBeFalse();
});
