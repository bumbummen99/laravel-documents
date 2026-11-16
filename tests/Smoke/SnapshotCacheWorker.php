<?php

declare(strict_types=1);

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

require $argv[1] ?? throw new InvalidArgumentException('Pass the Composer autoloader path.');

[$script, $autoload, $directory, $mode, $html, $marker, $release] = $argv;
$app = new Application(__DIR__);
Facade::setFacadeApplication($app);
$app->instance('files', new Filesystem);
$app->instance('config', new Repository([
    'cache' => [
        'default' => 'file',
        'stores' => ['file' => ['driver' => 'file', 'path' => $directory, 'lock_path' => $directory]],
        'prefix' => 'documents-worker-test',
    ],
    'laravel-documents' => [
        'snapshot_cache' => ['store' => 'file', 'lock_seconds' => 10, 'lock_wait_seconds' => 5],
    ],
]));
$app->register(CacheServiceProvider::class);
$rendered = new RenderedDocument($html, new DocumentTarget(
    'disk',
    new FilesystemDocumentStorageContext('same-document.pdf', 'documents'),
));
$cache = $app->make(DocumentSnapshotCache::class);

if ($mode === 'changed') {
    echo $cache->changed($rendered) ? 'changed' : 'unchanged';

    return;
}

if ($mode !== 'publish') {
    throw new InvalidArgumentException('Unknown worker mode.');
}

file_put_contents($marker.'.started', 'started');
$cache->publish(new GeneratedDocument($rendered, 'PDF', 'worker-generation'), function () use ($marker, $release) {
    file_put_contents($marker.'.inside', 'inside');

    if ($release !== '-') {
        $deadline = microtime(true) + 8;

        while (! is_file($release) && microtime(true) < $deadline) {
            usleep(20_000);
        }

        if (! is_file($release)) {
            throw new RuntimeException('Timed out waiting to release the publication.');
        }
    }

    return null;
});
