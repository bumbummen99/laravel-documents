<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\QueueTables;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;

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
}
