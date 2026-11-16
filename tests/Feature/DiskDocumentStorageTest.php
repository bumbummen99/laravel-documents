<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DiskDocumentStorage;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;

beforeEach(function () {
    Storage::fake('documents');
});

it('rejects version identifiers for unversioned storage', function (string $operation, string $version) {
    $storage = new DiskDocumentStorage;
    $context = new FilesystemDocumentStorageContext('invoice.pdf', 'documents');

    expect(fn () => $storage->{$operation}($context, $version))
        ->toThrow(InvalidArgumentException::class, DiskDocumentStorage::class.' does not keep versions.');
})->with(['get', 'exists'])->with(['version-1', '']);

it('requires a filesystem context for every storage operation', function (string $operation, array $arguments) {
    $storage = new DiskDocumentStorage;

    expect(fn () => $storage->{$operation}(new RecordStorageContext(42), ...$arguments))
        ->toThrow(InvalidArgumentException::class, 'Filesystem storage requires '.FilesystemDocumentStorageContext::class.'.');
})->with([
    'write' => ['write', ['PDF']],
    'get' => ['get', []],
    'exists' => ['exists', []],
    'versions' => ['versions', []],
]);

it('uses the configured lock lease and waits twice as long for a disk write', function () {
    $context = new FilesystemDocumentStorageContext('invoice.pdf', 'documents');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(4)->andReturnTrue();
    $lock->shouldReceive('release')->once()->withNoArgs()->andReturnTrue();
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->once()->with(DiskDocumentStorage::writeLockKey($context), 2)->andReturn($lock);
    app('cache')->extend('disk-write-test', fn () => new Repository($store));
    Config::set('cache.stores.disk-write-test', ['driver' => 'disk-write-test']);
    Config::set('cache.default', 'disk-write-test');

    expect((new DiskDocumentStorage(lockTimeout: 2))->write($context, 'PDF'))->toBeNull()
        ->and(Storage::disk('documents')->get($context->path))->toBe('PDF')
        ->and((new DiskDocumentStorage)->lockTimeout)->toBe(10);
});

it('does not release an unacquired lock or write after a lock timeout', function () {
    $context = new FilesystemDocumentStorageContext('invoice.pdf', 'documents');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->with(4)->andThrow(new LockTimeoutException);
    $lock->shouldNotReceive('release');
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->once()->with(DiskDocumentStorage::writeLockKey($context), 2)->andReturn($lock);
    app('cache')->extend('disk-timeout-test', fn () => new Repository($store));
    Config::set('cache.stores.disk-timeout-test', ['driver' => 'disk-timeout-test']);
    Config::set('cache.default', 'disk-timeout-test');

    expect(fn () => (new DiskDocumentStorage(lockTimeout: 2))->write($context, 'PDF'))
        ->toThrow(LockTimeoutException::class)
        ->and(Storage::disk('documents')->exists($context->path))->toBeFalse();
});

it('propagates a failed disk write instead of reporting success', function () {
    $files = Mockery::mock(FilesystemAdapter::class);
    $files->shouldReceive('put')->once()->with('invoice.pdf', 'PDF')->andReturn(false);
    Storage::shouldReceive('disk')->once()->with('documents')->andReturn($files);
    $context = new FilesystemDocumentStorageContext('invoice.pdf', 'documents');

    expect(fn () => (new DiskDocumentStorage)->write($context, 'PDF'))
        ->toThrow(RuntimeException::class, 'Failed to write the generated PDF.');
});

it('keeps disk write keys stable and isolated by target', function () {
    $context = new FilesystemDocumentStorageContext('invoice.pdf', 'documents');
    $other = new FilesystemDocumentStorageContext('other.pdf', 'documents');

    expect(DiskDocumentStorage::writeLockKey($context))
        ->toBe(DiskDocumentStorage::class.':'.hash('sha256', $context->identity()))
        ->and(DiskDocumentStorage::writeLockKey($other))->not->toBe(DiskDocumentStorage::writeLockKey($context));
});
