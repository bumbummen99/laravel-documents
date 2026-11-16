<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use RuntimeException;

final class FailingSnapshotCacheStore extends ArrayStore
{
    public bool $failWrite = false;

    public bool $throwOnWrite = false;

    public bool $failDelete = false;

    public bool $failRelease = false;

    public bool $throwOnRelease = false;

    /** @var list<string> */
    public array $lockNames = [];

    /** @var array<string, mixed> */
    public array $fingerprints = [];

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockNames[] = $name;

        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            public function __construct(
                private readonly FailingSnapshotCacheStore $cache,
                string $name,
                int $seconds,
                ?string $owner,
            ) {
                parent::__construct($cache, $name, $seconds, $owner);
            }

            public function release()
            {
                if ($this->cache->throwOnRelease) {
                    throw new RuntimeException('Lock release threw.');
                }

                return $this->cache->failRelease ? false : parent::release();
            }
        };
    }

    public function forever($key, $value)
    {
        $this->fingerprints[$key] = $value;

        if ($this->throwOnWrite) {
            throw new RuntimeException('Snapshot cache write threw.');
        }

        return $this->failWrite ? false : parent::forever($key, $value);
    }

    public function forget($key)
    {
        return $this->failDelete ? false : parent::forget($key);
    }
}
