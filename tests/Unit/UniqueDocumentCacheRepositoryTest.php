<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use SkyRaptor\LaravelDocuments\Jobs\UniqueDocumentCacheRepository;

function documentFrameworkLockKey(string $job, string $identity = 'records:42'): string
{
    return 'laravel_unique_job:'.$job.':'.hash('xxh128', $identity);
}

it('acquires and restores one target lock independently of the framework job key', function () {
    $repository = new UniqueDocumentCacheRepository(new ArrayStore);
    $lock = $repository->lock(documentFrameworkLockKey('FirstJob'), 60);

    expect($lock->get())->toBeTrue()
        ->and($repository->lock(documentFrameworkLockKey('SecondJob'), 60)->get())->toBeFalse();

    $restored = $repository->restoreLock(documentFrameworkLockKey('AnotherJob'), $lock->owner());

    expect($restored->release())->toBeTrue()
        ->and($repository->lock(documentFrameworkLockKey('ThirdJob'), 60)->get())->toBeTrue()
        ->and($repository->getName())->toBe(UniqueDocumentCacheRepository::STORE_NAME);
});

it('forwards ordinary store methods without rewriting their arguments', function () {
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('getPrefix')->once()->withNoArgs()->andReturn('custom-prefix:');
    $repository = new UniqueDocumentCacheRepository($store);

    expect($repository->getPrefix())->toBe('custom-prefix:');
});

it('delegates target lock creation to the store exactly once', function () {
    $lock = Mockery::mock(Lock::class);
    $store = Mockery::mock(Store::class, LockProvider::class);
    $store->shouldReceive('lock')->once()->with('laravel_unique_document:'.hash('xxh128', 'records:42'), 60)->andReturn($lock);
    $repository = new UniqueDocumentCacheRepository($store);

    expect($repository->lock(documentFrameworkLockKey('DocumentJob'), 60))->toBe($lock);
    $store->shouldHaveReceived('lock')->once();
});

it('does not let cache macros replace the document target lock provider', function () {
    UniqueDocumentCacheRepository::macro('lock', fn () => throw new LogicException('A cache macro intercepted the document lock.'));

    try {
        $repository = new UniqueDocumentCacheRepository(new ArrayStore);

        expect($repository->lock(documentFrameworkLockKey('DocumentJob'), 60)->get())->toBeTrue();
    } finally {
        UniqueDocumentCacheRepository::flushMacros();
    }
});

it('preserves arguments when a store does not implement the locking contract', function () {
    $store = Mockery::mock(Store::class);
    $store->shouldReceive('lock')->once()->with('original-key', 5)->andReturn('store-specific-result');
    $repository = new UniqueDocumentCacheRepository($store);

    expect($repository->lock('original-key', 5))->toBe('store-specific-result');
});

it('keeps different target locks independent', function () {
    $repository = new UniqueDocumentCacheRepository(new ArrayStore);

    expect($repository->lock(documentFrameworkLockKey('DocumentJob'))->get())->toBeTrue()
        ->and($repository->lock(documentFrameworkLockKey('DocumentJob', 'records:43'))->get())->toBeTrue();
});

it('does not release a replacement lock owned by another job', function () {
    $repository = new UniqueDocumentCacheRepository(new ArrayStore);
    $original = $repository->lock(documentFrameworkLockKey('OriginalJob'), 60);
    expect($original->get())->toBeTrue();
    $original->release();
    $replacement = $repository->lock(documentFrameworkLockKey('ReplacementJob'), 60);

    expect($replacement->get())->toBeTrue()
        ->and($repository->restoreLock(documentFrameworkLockKey('OriginalJob'), $original->owner())->release())->toBeFalse()
        ->and($repository->lock(documentFrameworkLockKey('ThirdJob'))->get())->toBeFalse();
});

it('rejects malformed framework unique lock keys', function (string $key) {
    $repository = new UniqueDocumentCacheRepository(new ArrayStore);

    expect(fn () => $repository->lock($key))
        ->toThrow(InvalidArgumentException::class, 'Expected a Laravel unique-job lock key');
})->with(['invalid', 'other:DocumentJob:records:42', 'laravel_unique_job::records:42', 'laravel_unique_job:DocumentJob:']);
