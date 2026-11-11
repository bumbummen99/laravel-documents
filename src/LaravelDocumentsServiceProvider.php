<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Support\ServiceProvider;
use SkyRaptor\LaravelDocuments\Console\Commands\LaravelDocumentsCommand;

class LaravelDocumentsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-documents.php', 'laravel-documents');

        $this->app->singleton(LaravelDocuments::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laravel-documents.php');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-documents');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-documents.php' => config_path('laravel-documents.php'),
        ], ['laravel-documents', 'laravel-documents-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-documents'),
        ], ['laravel-documents', 'laravel-documents-lang']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-documents', 'laravel-documents-migrations']);

        $this->commands([
            LaravelDocumentsCommand::class,
        ]);
    }
}
