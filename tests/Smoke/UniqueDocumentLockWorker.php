<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\LaravelDocumentsServiceProvider;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

require $argv[1] ?? throw new InvalidArgumentException('Pass the Composer autoloader path.');

$directory = $argv[2] ?? throw new InvalidArgumentException('Pass the cache directory.');
$app = new Application(__DIR__);
Facade::setFacadeApplication($app);
$app->instance('files', new Filesystem);
$app->instance('config', new Repository([
    'cache' => [
        'default' => 'second',
        'stores' => ['second' => [
            'driver' => 'file',
            'path' => $directory.'/second',
            'lock_path' => $directory.'/second',
        ]],
        'prefix' => '',
    ],
]));
$app->register(CacheServiceProvider::class);
$app->register(LaravelDocumentsServiceProvider::class);
$job = new GenerateUniqueDocument(new RenderedDocument(
    'frozen HTML',
    new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoice.pdf', 'documents')),
));
$locks = new UniqueLock($app->make(CacheRepository::class));
$acquired = $locks->acquire($job);

echo $acquired ? 'acquired' : 'blocked';

if ($acquired) {
    $locks->release($job);
}
