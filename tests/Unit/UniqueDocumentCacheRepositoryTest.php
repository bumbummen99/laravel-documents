<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use SkyRaptor\LaravelDocuments\Jobs\UniqueDocumentCacheRepository;

it('acquires and restores one target lock independently of the framework job key', function () {
    $repository = new UniqueDocumentCacheRepository(new ArrayStore, 'document-target');
    $lock = $repository->lock('first-job-class', 60);

    expect($lock->get())->toBeTrue()
        ->and($repository->lock('second-job-class', 60)->get())->toBeFalse();

    $restored = $repository->restoreLock('another-job-class', $lock->owner());

    expect($restored->release())->toBeTrue()
        ->and($repository->lock('third-job-class', 60)->get())->toBeTrue();
});

it('forwards ordinary store methods without rewriting their arguments', function () {
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('getPrefix')->once()->withNoArgs()->andReturn('custom-prefix:');
    $repository = new UniqueDocumentCacheRepository($store, 'document-target');

    expect($repository->getPrefix())->toBe('custom-prefix:');
});

it('delegates target lock creation to the store exactly once', function () {
    $lock = Mockery::mock(Lock::class);
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->once()->with('document-target', 60)->andReturn($lock);
    $repository = new UniqueDocumentCacheRepository($store, 'document-target');

    expect($repository->lock('framework-job-key', 60))->toBe($lock);
    $store->shouldHaveReceived('lock')->once();
});

it('does not let cache macros replace the document target lock provider', function () {
    UniqueDocumentCacheRepository::macro('lock', fn () => throw new LogicException('A cache macro intercepted the document lock.'));

    try {
        $repository = new UniqueDocumentCacheRepository(new ArrayStore, 'document-target');

        expect($repository->lock('framework-job-key', 60)->get())->toBeTrue();
    } finally {
        UniqueDocumentCacheRepository::flushMacros();
    }
});

it('preserves arguments when a store does not implement the locking contract', function () {
    $store = Mockery::mock(Store::class);
    $store->shouldReceive('lock')->once()->with('original-key', 5)->andReturn('store-specific-result');
    $repository = new UniqueDocumentCacheRepository($store, 'document-target');

    expect($repository->lock('original-key', 5))->toBe('store-specific-result');
});
