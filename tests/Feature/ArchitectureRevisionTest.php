<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\ConfiguredPdfDriver;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\IndependentGenerateDocument;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\PdfOptions;

beforeEach(function () {
    app()->instance(RecordDocumentStorage::class, new RecordDocumentStorage);
    app(DocumentStorageManager::class)->register('records', fn () => app(RecordDocumentStorage::class), GenerateVersionedDocument::class);
    app(DocumentPdfDriverManager::class)->register(
        'invoice',
        fn () => new ConfiguredPdfDriver('dispatch'),
        fn (PdfBuilder $builder) => $builder->format('A4'),
    );
});

it('resolves named configurations in the worker without serializing them or the document', function () {
    $document = new class extends Document
    {
        public static string $storage = 'records';

        public static string $pdfDriver = 'invoice';

        public string $body = 'original';

        public function html(): string
        {
            return $this->body;
        }

        public function storageContext(): DocumentStorageContext
        {
            return new RecordStorageContext(42);
        }
    };

    $job = app(DocumentJobFactory::class)->make($document->render());
    $serialized = serialize($job);
    $dispatchStorage = app(RecordDocumentStorage::class);
    $workerStorage = new RecordDocumentStorage;
    $document->body = 'modified after dispatch';
    app(DocumentStorageManager::class)->register('records', fn () => $workerStorage, GenerateVersionedDocument::class);
    app(DocumentPdfDriverManager::class)->register(
        'invoice',
        fn () => new ConfiguredPdfDriver('worker'),
        fn (PdfBuilder $builder) => $builder->format('Letter'),
    );

    $restored = unserialize($serialized);
    $restored->handle();

    expect(interface_exists(GenerateDocument::class))->toBeTrue()
        ->and($restored)->toBeInstanceOf(GenerateDocument::class)
        ->and($dispatchStorage->exists(new RecordStorageContext(42)))->toBeFalse()
        ->and($workerStorage->get(new RecordStorageContext(42)))->toBe('worker:Letter:original')
        ->and($document->exists())->toBeTrue()
        ->and($document->get())->toBe('worker:Letter:original')
        ->and($document->versions())->toBe([])
        ->and($serialized)->not->toContain(RecordDocumentStorage::class)
        ->and($serialized)->not->toContain(ConfiguredPdfDriver::class);
});

it('accepts a generation job implementing the contract without extending the base class', function () {
    $document = new class extends Document
    {
        public static string $storage = 'records';

        public static string $pdfDriver = 'invoice';

        public function html(): string
        {
            return 'frozen';
        }

        public function storageContext(): DocumentStorageContext
        {
            return new RecordStorageContext(46);
        }
    };
    $job = app(DocumentJobFactory::class)->make($document->render(), connection: 'redis', queue: 'pdf', job: IndependentGenerateDocument::class);
    $restored = unserialize(serialize($job));
    $restored->handle();

    expect($restored)->toBeInstanceOf(GenerateDocument::class)
        ->and($restored->connection)->toBe('redis')
        ->and($restored->queue)->toBe('pdf')
        ->and(app(RecordDocumentStorage::class)->get(new RecordStorageContext(46)))->toBe('dispatch:A4:frozen');
});

it('applies registered Browsershot customization without starting a browser', function (bool $configureBuilder) {
    $built = null;
    $driver = new class extends BrowsershotDriver
    {
        public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
        {
            $this->buildBrowsershot($html, $headerHtml, $footerHtml, $options);

            return 'PDF';
        }
    };
    $customize = function (Browsershot $browsershot) use (&$built) {
        $built = $browsershot;
        $browsershot->setOption('preferCSSPageSize', true);
    };

    if (! $configureBuilder) {
        $driver->customizeBrowsershot($customize);
    }

    app(DocumentPdfDriverManager::class)->register(
        'custom-browser',
        fn () => $driver,
        $configureBuilder ? fn (PdfBuilder $builder) => $builder->withBrowsershot($customize) : null,
    );
    $rendered = new RenderedDocument('HTML', new DocumentTarget('records', new RecordStorageContext(47)), 'custom-browser');

    expect(app(DocumentGenerator::class)->generate($rendered)->contents)->toBe('PDF')
        ->and($built)->toBeInstanceOf(Browsershot::class);

    $options = (new ReflectionProperty(Browsershot::class, 'additionalOptions'))->getValue($built);
    expect($options['preferCSSPageSize'])->toBeTrue()
        ->and($options['margin'])->toBe(['top' => '0mm', 'right' => '0mm', 'bottom' => '0mm', 'left' => '0mm']);
})->with([
    'driver factory' => [false],
    'builder callback' => [true],
]);

it('retains the selected Browsershot Lambda mode', function (bool $configureBuilder) {
    $driver = new class extends BrowsershotDriver
    {
        public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
        {
            return $this->onLambda ? 'Lambda' : 'local';
        }
    };

    if (! $configureBuilder) {
        $driver->onLambda();
    }

    app(DocumentPdfDriverManager::class)->register(
        'lambda',
        fn () => $driver,
        $configureBuilder ? fn (PdfBuilder $builder) => $builder->onLambda() : null,
    );
    $rendered = new RenderedDocument('HTML', new DocumentTarget('records', new RecordStorageContext(47)), 'lambda');

    expect(app(DocumentGenerator::class)->generate($rendered)->contents)->toBe('Lambda');
})->with([
    'driver factory' => [false],
    'builder callback' => [true],
]);

it('resolves the default driver from the container at execution time', function () {
    $driver = new ConfiguredPdfDriver('bound');
    app()->instance(PdfDriver::class, $driver);
    $rendered = new RenderedDocument('HTML', new DocumentTarget('records', new RecordStorageContext(48)));

    expect(app(DocumentGenerator::class)->generate($rendered)->contents)->toBe('bound::HTML');
});

it('rejects unknown configuration identifiers explicitly', function () {
    $target = new DocumentTarget('unregistered-storage', new RecordStorageContext(49));
    $rendered = new RenderedDocument('HTML', $target, 'unregistered-driver');

    expect(fn () => app(DocumentGenerator::class)->generate($rendered))->toThrow(InvalidArgumentException::class, 'PDF driver configuration [unregistered-driver]')
        ->and(fn () => app(DocumentStorageManager::class)->resolve($target->storage))
        ->toThrow(InvalidArgumentException::class, 'storage configuration [unregistered-storage]');
});

it('keeps snapshots and their target metadata readonly', function () {
    $rendered = new RenderedDocument('HTML', new DocumentTarget('records', new RecordStorageContext(50)), 'invoice');

    expect((new ReflectionClass($rendered))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($rendered->target))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($rendered->target->context))->isReadOnly())->toBeTrue()
        ->and(fn () => $rendered->html = 'changed')->toThrow(Error::class);
});

it('checks the last successful snapshot in cache without reading the stored PDF', function () {
    $document = new class extends Document
    {
        public static string $storage = 'records';

        public static string $pdfDriver = 'invoice';

        public string $body = 'original';

        public function html(): string
        {
            return $this->body;
        }

        public function storageContext(): DocumentStorageContext
        {
            return new RecordStorageContext(43);
        }
    };

    $rendered = $document->render();
    $cache = app(DocumentSnapshotCache::class);
    $jobs = app(DocumentJobFactory::class);

    expect($cache->changed($rendered))->toBeTrue()
        ->and($jobs->makeIfChanged($rendered))->toBeInstanceOf(GenerateVersionedDocument::class);

    app(DocumentPublisher::class)->publish(app(DocumentGenerator::class)->generate($rendered));

    expect($cache->changed($rendered))->toBeFalse()
        ->and($jobs->makeIfChanged($rendered))->toBeNull();

    $document->body = 'new content';

    expect($cache->changed($document->render()))->toBeTrue()
        ->and($jobs->makeIfChanged($document->render()))->toBeInstanceOf(GenerateVersionedDocument::class);
});

it('does not impose filesystem parameters on custom storage contexts', function () {
    $context = new RecordStorageContext(44);

    $storage = app(RecordDocumentStorage::class);
    $storage->write($context, 'PDF');

    expect(unserialize(serialize($context)))->toEqual($context)
        ->and($storage->get($context))->toBe('PDF')
        ->and($storage->exists($context))->toBeTrue()
        ->and($storage->versions($context))->toBe([]);
});

it('rejects a context that a storage implementation cannot use', function () {
    (new DiskDocumentStorage)->write(new RecordStorageContext(45), 'PDF');
})->throws(InvalidArgumentException::class, 'Filesystem storage requires '.FilesystemDocumentStorageContext::class.'.');
