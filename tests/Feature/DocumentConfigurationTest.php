<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\ConfiguredPdfDriver;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\PdfOptions;
use Spatie\LaravelPdf\PdfServiceProvider;

it('rejects an empty PDF configuration name', function () {
    expect(fn () => app(DocumentPdfDriverManager::class)->register('', fn () => new ConfiguredPdfDriver('test')))
        ->toThrow(InvalidArgumentException::class, 'A document PDF driver configuration needs a name.');
});

it('defines complete zero-margin PDF defaults', function () {
    app()->instance(PdfDriver::class, new ConfiguredPdfDriver('test'));

    expect(app(DocumentPdfDriverManager::class)->builder('default')->margins)
        ->toBe(['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0, 'unit' => 'mm']);
});

it('rejects view rendering during PDF generation', function (string $method) {
    $manager = app(DocumentPdfDriverManager::class);
    $manager->register(
        'view',
        fn () => new ConfiguredPdfDriver('test'),
        fn (PdfBuilder $builder) => $builder->{$method}('invoice'),
    );

    expect(fn () => $manager->builder('view'))
        ->toThrow(InvalidArgumentException::class, 'Document PDF builders must use rendered HTML, not views.');
})->with(['view', 'headerView', 'footerView']);

it('creates an independent configured PDF builder for each generation', function () {
    $manager = app(DocumentPdfDriverManager::class);
    $manager->register(
        'configured',
        fn () => new ConfiguredPdfDriver('test'),
        fn (PdfBuilder $builder) => $builder->format('A4')->meta(title: 'Invoice'),
    );
    $first = $manager->builder('configured');
    $first->format('Letter')->meta(title: 'Modified');
    $second = $manager->builder('configured');

    expect($second)->not->toBe($first)
        ->and($second->format)->toBe('A4')
        ->and($second->metadata?->title)->toBe('Invoice');
});

it('isolates shared Browsershot settings while preserving factory defaults', function (bool $selectNamedDriver, bool $failCustomized) {
    $driver = new class extends BrowsershotDriver
    {
        public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
        {
            $browser = Browsershot::html($html);

            if ($this->customizeBrowsershot !== null) {
                ($this->customizeBrowsershot)($browser);
            }

            $settings = (new ReflectionProperty(Browsershot::class, 'additionalOptions'))->getValue($browser);

            return ($this->onLambda ? 'lambda:' : 'local:').$settings['marker'];
        }
    };
    $driver->customizeBrowsershot(fn (Browsershot $browser) => $browser->setOption('marker', 'factory'));
    app()->instance(PdfDriver::class, $driver);
    app()->instance('laravel-pdf.driver.shared', $driver);
    $manager = app(DocumentPdfDriverManager::class);
    $configure = function (PdfBuilder $builder) use ($selectNamedDriver): void {
        if ($selectNamedDriver) {
            $builder->driver('shared');
        }
    };
    $manager->register('plain', fn () => app(PdfDriver::class), $configure);
    $manager->register('customized', fn () => app(PdfDriver::class), function (PdfBuilder $builder) use ($configure, $failCustomized): void {
        $configure($builder);
        $builder->onLambda()->withBrowsershot(function (Browsershot $browser) use ($failCustomized): void {
            if ($failCustomized) {
                throw new RuntimeException('Browsershot customization failed.');
            }

            $browser->setOption('marker', 'builder');
        });
    });

    expect($manager->builder('plain')->html('HTML')->generatePdfContent())->toBe('local:factory');

    for ($attempt = 0; $attempt < 2; $attempt++) {
        if ($failCustomized) {
            expect(fn () => $manager->builder('customized')->html('HTML')->generatePdfContent())
                ->toThrow(RuntimeException::class, 'Browsershot customization failed.');
        } else {
            expect($manager->builder('customized')->html('HTML')->generatePdfContent())->toBe('lambda:builder');
        }

        expect($manager->builder('plain')->html('HTML')->generatePdfContent())->toBe('local:factory');
    }

    expect($driver->generatePdf('HTML', null, null, new PdfOptions))->toBe('local:factory');
})->with([
    'factory driver' => [false],
    'builder-selected driver' => [true],
])->with([
    'successful generation' => [false],
    'failed generation' => [true],
]);

it('isolates automatic PDF caches between registered configurations', function () {
    $this->app->register(PdfServiceProvider::class);
    config(['laravel-pdf.cache.store' => 'array']);
    $manager = app(DocumentPdfDriverManager::class);

    foreach (['alpha', 'beta'] as $name) {
        $driver = Mockery::mock(PdfDriver::class);
        $driver->shouldReceive('generatePdf')->once()->andReturn($name.' PDF');
        $manager->register($name, fn () => $driver, fn (PdfBuilder $builder) => $builder->cache());
    }

    foreach (['alpha', 'beta', 'alpha', 'beta'] as $name) {
        expect($manager->builder($name)->html('same HTML')->generatePdfContent())->toBe($name.' PDF');
    }
});

it('keeps HTML and builder options in automatic PDF cache fingerprints', function () {
    $this->app->register(PdfServiceProvider::class);
    config(['laravel-pdf.cache.store' => 'array']);
    $manager = app(DocumentPdfDriverManager::class);
    $manager->register('cached', fn () => new ConfiguredPdfDriver('cached'), fn (PdfBuilder $builder) => $builder->cache());

    foreach ([['first', 'A4'], ['second', 'A4'], ['first', 'Letter'], ['first', 'A4']] as [$html, $format]) {
        expect($manager->builder('cached')->html($html)->format($format)->generatePdfContent())
            ->toBe("cached:{$format}:{$html}");
    }
});

it('preserves explicit PDF cache keys', function () {
    $this->app->register(PdfServiceProvider::class);
    config(['laravel-pdf.cache.store' => 'array', 'laravel-pdf.cache.prefix' => 'documents-test']);
    $manager = app(DocumentPdfDriverManager::class);
    $driver = Mockery::mock(PdfDriver::class);
    $driver->shouldReceive('generatePdf')->once()->andReturn('PDF');
    $manager->register('cached', fn () => $driver, fn (PdfBuilder $builder) => $builder->cache(key: 'explicit-key'));

    expect($manager->builder('cached')->html('HTML')->generatePdfContent())->toBe('PDF')
        ->and($manager->builder('cached')->html('HTML')->generatePdfContent())->toBe('PDF')
        ->and(app('cache')->store('array')->has('documents-test:explicit-key'))->toBeTrue();
});

it('rejects invalid storage registrations', function (string $name, string $job) {
    expect(fn () => app(DocumentStorageManager::class)->register($name, fn () => new DiskDocumentStorage, $job))
        ->toThrow(InvalidArgumentException::class, 'A storage configuration needs a name and a document generation job.');
})->with([
    'empty name' => ['', GenerateUniqueDocument::class],
    'invalid job' => ['custom', stdClass::class],
]);

it('uses an inherited configured document path', function () {
    $document = new class extends Document
    {
        public static ?string $path = 'invoices/configured.pdf';
    };

    expect($document->path())->toBe('invoices/configured.pdf');
});

it('rejects a missing configured document path', function (?string $path) {
    $document = new class extends Document {};
    $document::$path = $path;

    expect(fn () => $document->path())
        ->toThrow(LogicException::class, 'The document path must be configured or overridden.');
})->with([null, '']);

it('forwards versioned reads without rendering the document', function () {
    $context = new RecordStorageContext(42);
    $storage = Mockery::mock(DocumentStorage::class);
    $storage->shouldReceive('get')->once()->withArgs(fn ($target, $version) => $target == $context && $version === 'version-1')->andReturn('PDF');
    $storage->shouldReceive('exists')->once()->withArgs(fn ($target, $version) => $target == $context && $version === 'version-1')->andReturn(true);
    $storage->shouldReceive('versions')->once()->withArgs(fn ($target) => $target == $context)->andReturn(['version-1', 'version-2']);
    app(DocumentStorageManager::class)->register('records', fn () => $storage);
    $document = new class extends Document
    {
        public static string $storage = 'records';

        public function storageContext(): DocumentStorageContext
        {
            return new RecordStorageContext(42);
        }

        public function html(): string
        {
            throw new LogicException('Stored reads must not render HTML.');
        }
    };

    expect($document->get('version-1'))->toBe('PDF')
        ->and($document->exists('version-1'))->toBeTrue()
        ->and($document->versions())->toBe(['version-1', 'version-2']);
});

it('explains the missing optional versioned storage dependency', function () {
    expect(fn () => app(DocumentStorageManager::class)->resolve('versioned'))
        ->toThrow(LogicException::class, VersionedDocumentStorage::class.' requires the skyraptor/laravel-versioned-storage package.');
})->skip(fn () => class_exists(VersionedStorage::class), 'Requires an environment without optional versioned storage.');
