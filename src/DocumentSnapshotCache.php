<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class DocumentSnapshotCache
{
    public function __construct(private readonly CacheManager $cache) {}

    public function changed(RenderedDocument $rendered): bool
    {
        $store = $this->store();
        $this->lockProvider($store);

        return $store->get($this->key($rendered->target)) !== $rendered->fingerprint();
    }

    /**
     * Serialize publication and fingerprint updates for each storage target.
     *
     * @param  Closure(): ?string  $write
     */
    public function publish(GeneratedDocument $generated, Closure $write): ?string
    {
        $store = $this->store();
        $lockProvider = $this->lockProvider($store);

        $lockSeconds = $this->positiveInteger('lock_seconds');
        $waitSeconds = $this->positiveInteger('lock_wait_seconds');
        $key = $this->key($generated->target);
        $fingerprint = $generated->fingerprint;
        $lock = $lockProvider->lock($key.':lock', $lockSeconds);
        $lock->block($waitSeconds);

        try {
            if ($store->has($key) && ! $store->forget($key)) {
                throw new RuntimeException('Failed to invalidate the previous document snapshot.');
            }

            $version = $write();

            try {
                if (! $store->forever($key, $fingerprint)) {
                    throw new RuntimeException('Failed to cache the stored document snapshot.');
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            return $version;
        } finally {
            try {
                if (! $lock->release()) {
                    throw new RuntimeException('Failed to release the document snapshot lock.');
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function store(): Repository
    {
        $name = config('laravel-documents.snapshot_cache.store');

        if ($name !== null && (! is_string($name) || $name === '')) {
            throw new InvalidArgumentException('The document snapshot cache store must be a non-empty name or null.');
        }

        return $this->cache->store($name);
    }

    private function lockProvider(Repository $store): LockProvider
    {
        $provider = $store->getStore();

        if (! $provider instanceof LockProvider || $provider instanceof NullStore) {
            throw new LogicException('The document snapshot cache must persist values and support atomic locks.');
        }

        return $provider;
    }

    private function positiveInteger(string $option): int
    {
        $value = config('laravel-documents.snapshot_cache.'.$option);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("The document snapshot cache option [{$option}] must be a positive integer.");
        }

        return $value;
    }

    private function key(DocumentTarget $target): string
    {
        return 'laravel_documents:snapshot:'.hash('sha256', $target->context->identity());
    }
}
