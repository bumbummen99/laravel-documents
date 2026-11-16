<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use Spatie\LaravelPdf\Drivers\PdfDriver;

class LaravelDocumentsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/laravel-documents.php', 'laravel-documents');

        $this->app->singleton(DocumentSnapshotCache::class);
        $this->app->singleton(DocumentStorageManager::class, function (Application $app): DocumentStorageManager {
            $manager = new DocumentStorageManager($app);
            $manager->register('disk', fn (Container $app): DiskDocumentStorage => $app->make(DiskDocumentStorage::class));
            $manager->register('versioned', fn (Container $app): VersionedDocumentStorage => $app->make(VersionedDocumentStorage::class), GenerateVersionedDocument::class);

            return $manager;
        });
        $this->app->singleton(DocumentPdfDriverManager::class, function (Application $app): DocumentPdfDriverManager {
            $manager = new DocumentPdfDriverManager($app);
            $manager->register('default', fn (Container $app): PdfDriver => $app->make(PdfDriver::class));

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-documents.php' => config_path('laravel-documents.php'),
        ], ['laravel-documents', 'laravel-documents-config']);
    }
}
