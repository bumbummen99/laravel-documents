<?php

declare(strict_types=1);

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\Facades\Documents;
use SkyRaptor\LaravelDocuments\LaravelDocumentsServiceProvider;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfOptions;

require $argv[1] ?? throw new InvalidArgumentException('Pass the Composer autoloader path.');

foreach ([
    'Mateffy\\JobProgress\\Traits\\Progress',
    'SkyRaptor\\LaravelVersionedStorage\\VersionedStorage',
    'Spatie\\Browsershot\\Browsershot',
] as $optionalClass) {
    if (class_exists($optionalClass) || trait_exists($optionalClass)) {
        throw new RuntimeException("Unexpected optional dependency: {$optionalClass}");
    }
}

final readonly class SmokeStorageContext extends DocumentStorageContext
{
    public function identity(): string
    {
        return 'smoke:1';
    }
}

final class SmokeDocument extends Document
{
    public static string $storage = 'smoke';

    public static string $pdfDriver = 'smoke';

    public function html(): string
    {
        return 'frozen HTML';
    }

    public function storageContext(): DocumentStorageContext
    {
        return new SmokeStorageContext;
    }
}

final class SmokeStorage implements DocumentStorage
{
    public ?string $contents = null;

    public function write(DocumentStorageContext $context, string $contents): ?string
    {
        $this->contents = $contents;

        return null;
    }

    public function get(DocumentStorageContext $context, ?string $version = null): string
    {
        return $this->contents ?? throw new RuntimeException('Document not found.');
    }

    public function exists(DocumentStorageContext $context, ?string $version = null): bool
    {
        return $this->contents !== null;
    }

    public function versions(DocumentStorageContext $context): array
    {
        return [];
    }
}

final class SmokePdfDriver implements PdfDriver
{
    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        return 'pdf:'.$html;
    }

    public function savePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options, string $path): void
    {
        throw new LogicException('The package should not ask the PDF driver to store files.');
    }
}

$app = new Application(__DIR__);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository([
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']], 'prefix' => ''],
]));
$app->register(CacheServiceProvider::class);
$app->register(LaravelDocumentsServiceProvider::class);
$storage = new SmokeStorage;
$app->make(DocumentStorageManager::class)->register('smoke', fn () => $storage);
$app->make(DocumentPdfDriverManager::class)->register('smoke', fn () => new SmokePdfDriver);
$document = new SmokeDocument;
$job = unserialize(serialize(Documents::makeJob($document)));
$job->handle();

if ($document->get() !== 'pdf:frozen HTML' || $app->make(DocumentSnapshotCache::class)->changed($document->render())
    || Documents::makeJobIfChanged($document) !== null) {
    throw new RuntimeException('Document generation without optional integrations failed.');
}

echo "Document generation works without optional integrations.\n";
