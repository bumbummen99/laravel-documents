<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class DocumentSnapshotCache
{
    public function __construct(private readonly CacheManager $cache) {}

    /**
     * Record a rendered fingerprint under the same lock used for publication.
     */
    public function register(DocumentTarget $target, ?string $fingerprint = null): string
    {
        if (config('laravel-documents.coalescing.enabled')) {
            throw new LogicException('Coalesced snapshot registration requires the complete rendered snapshot.');
        }

        return $this->withTargetLock($target, function (Repository $store, string $key) use ($fingerprint): string {
            $latest = $store->get($key.':latest');

            if ($fingerprint !== null && is_string($latest)) {
                [$id, $registeredFingerprint] = array_pad(explode(':', $latest, 2), 2, null);

                if (Str::isUuid($id) && $registeredFingerprint === $fingerprint) {
                    return $latest;
                }
            }

            $snapshotId = (string) Str::uuid();

            if ($fingerprint !== null) {
                $snapshotId .= ':'.$fingerprint;
            }

            if (! $store->forever($key.':latest', $snapshotId)) {
                throw new RuntimeException('Failed to register the latest document snapshot.');
            }

            return $snapshotId;
        });
    }

    public function changed(RenderedDocument $rendered): bool
    {
        if (config('laravel-documents.coalescing.enabled')) {
            return app(DocumentCoalescer::class)->changed($rendered);
        }

        $store = $this->store();
        $this->lockProvider($store);

        return $store->get($this->key($rendered->target)) !== $rendered->fingerprint();
    }

    /**
     * Serialize publication and fingerprint updates for each storage target.
     *
     * @param  Closure(): ?string  $write
     * @param  Closure(string): void|null  $superseded
     * @return string|false|null False means that a newer snapshot superseded this result.
     */
    public function publish(GeneratedDocument $generated, Closure $write, ?Closure $superseded = null): string|false|null
    {
        if (config('laravel-documents.coalescing.enabled')) {
            throw new LogicException('Coalesced publication must use DocumentPublisher and versioned staging.');
        }

        $latestSnapshotId = null;
        $version = $this->withTargetLock($generated->target, function (Repository $store, string $key) use ($generated, $write, &$latestSnapshotId): string|false|null {
            if (($generated->snapshotId ?? null) !== null) {
                $latest = $store->get($key.':latest');

                if (! is_string($latest) || $latest === '') {
                    throw new RuntimeException('The latest document snapshot marker is missing.');
                }

                if ($latest !== $generated->snapshotId) {
                    $latestSnapshotId = $latest;

                    return false;
                }
            }

            if ($store->has($key) && ! $store->forget($key)) {
                throw new RuntimeException('Failed to invalidate the previous document snapshot.');
            }

            $version = $write();

            try {
                if (! $store->forever($key, $generated->fingerprint)) {
                    throw new RuntimeException('Failed to cache the stored document snapshot.');
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            return $version;
        });

        if ($latestSnapshotId !== null) {
            $superseded?->__invoke($latestSnapshotId);
        }

        return $version;
    }

    /**
     * @template T
     *
     * @param  Closure(Repository, string): T  $callback
     * @return T
     */
    private function withTargetLock(DocumentTarget $target, Closure $callback): mixed
    {
        $store = $this->store();
        $lockProvider = $this->lockProvider($store);

        $lockSeconds = $this->positiveInteger('lock_seconds');
        $waitSeconds = $this->positiveInteger('lock_wait_seconds');
        $key = $this->key($target);
        $lock = $lockProvider->lock($key.':lock', $lockSeconds);
        $lock->block($waitSeconds);

        try {
            return $callback($store, $key);
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
