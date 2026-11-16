<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Jobs\UniqueDocumentCacheRepository;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use Symfony\Component\Process\Process;

function cacheSwitchDocumentJob(): GenerateUniqueDocument
{
    return new GenerateUniqueDocument(new RenderedDocument(
        'frozen HTML',
        new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoice.pdf', 'documents')),
    ));
}

beforeEach(function () {
    config([
        'cache.default' => 'first',
        'cache.stores.first' => ['driver' => 'array'],
        'cache.stores.second' => ['driver' => 'array'],
    ]);
});

it('deduplicates jobs after switching a warmed adapter to another default store', function () {
    cacheSwitchDocumentJob()->uniqueVia();
    Cache::setDefaultDriver('second');
    $locks = new UniqueLock(Cache::store());
    $job = cacheSwitchDocumentJob();

    expect($locks->acquire($job))->toBeTrue();
    Cache::forgetDriver(UniqueDocumentCacheRepository::STORE_NAME);

    expect($locks->acquire(cacheSwitchDocumentJob()))->toBeFalse();
    $locks->release($job);
});

it('resolves each lock operation in the current cache while retaining existing lock ownership', function () {
    $adapter = cacheSwitchDocumentJob()->uniqueVia();
    $key = UniqueLock::getKey(cacheSwitchDocumentJob());
    $first = $adapter->lock($key, 60);
    expect($first->get())->toBeTrue();

    Cache::setDefaultDriver('second');
    expect($adapter->getStore())->toBe(Cache::store()->getStore());
    $second = $adapter->lock($key, 60);

    expect($second->get())->toBeTrue()
        ->and($first->release())->toBeTrue()
        ->and($adapter->restoreLock($key, $first->owner())->release())->toBeFalse()
        ->and($adapter->lock($key)->get())->toBeFalse()
        ->and($adapter->restoreLock($key, $second->owner())->release())->toBeTrue();

    Cache::setDefaultDriver('first');
    $again = $adapter->lock($key, 60);
    expect($again->get())->toBeTrue();
    $again->release();
});

it('follows a purged and rebuilt default cache store without rebuilding the adapter', function () {
    $adapter = cacheSwitchDocumentJob()->uniqueVia();
    $key = UniqueLock::getKey(cacheSwitchDocumentJob());
    $original = $adapter->lock($key, 60);
    expect($original->get())->toBeTrue();
    Cache::forgetDriver('first');
    $rebuilt = $adapter->lock($key, 60);

    expect($rebuilt->get())->toBeTrue();
    $original->release();
    $rebuilt->release();
});

it('revalidates the default cache after the adapter has been warmed', function (string $store, string $message) {
    Cache::extend('without-locks', fn () => new Repository(Mockery::mock(Store::class)));
    config(['cache.stores.without-locks' => ['driver' => 'without-locks']]);
    $adapter = cacheSwitchDocumentJob()->uniqueVia();
    Cache::setDefaultDriver($store);

    expect(fn () => $adapter->lock(UniqueLock::getKey(cacheSwitchDocumentJob()), 60))
        ->toThrow(LogicException::class, $message);

    Cache::setDefaultDriver('second');
    $recovered = $adapter->lock(UniqueLock::getKey(cacheSwitchDocumentJob()), 60);
    expect($recovered->get())->toBeTrue();
    $recovered->release();
})->with([
    'null' => ['null', 'Unique document jobs require a default cache store with atomic locks.'],
    'without locks' => ['without-locks', 'Unique document jobs require a default cache store with atomic locks.'],
    'internal adapter' => [UniqueDocumentCacheRepository::STORE_NAME, 'The internal document lock cache cannot be the default cache store.'],
]);

it('shares the switched default stores unique lock with an independent process', function () {
    Storage::fake('unique-cache');
    $directory = Storage::disk('unique-cache')->path('shared-locks');

    foreach (['first', 'second'] as $name) {
        config(['cache.stores.'.$name => [
            'driver' => 'file',
            'path' => $directory.'/'.$name,
            'lock_path' => $directory.'/'.$name,
        ]]);
    }

    $autoload = null;

    foreach (ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
        if ($loader->findFile(RenderedDocument::class) !== false) {
            $autoload = $vendorDirectory.'/autoload.php';

            break;
        }
    }

    if ($autoload === null) {
        throw new RuntimeException('Cannot find the Composer autoloader for the document package.');
    }

    cacheSwitchDocumentJob()->uniqueVia();
    Cache::setDefaultDriver('second');
    $locks = new UniqueLock(Cache::store());
    $job = cacheSwitchDocumentJob();
    $acquired = false;

    try {
        $acquired = $locks->acquire($job);
        expect($acquired)->toBeTrue();
        $worker = new Process([PHP_BINARY, __DIR__.'/../Smoke/UniqueDocumentLockWorker.php', $autoload, $directory]);
        $worker->setTimeout(10)->mustRun();

        expect($worker->getOutput())->toBe('blocked');
    } finally {
        if ($acquired) {
            $locks->release($job);
        }

        File::deleteDirectory($directory);
    }
});
