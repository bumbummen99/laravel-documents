<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * Routes framework unique-job locks for a document job to its target key,
 * rather than including the concrete job class in the key.
 */
final class UniqueDocumentCacheRepository extends Repository
{
    public function __construct(
        Store $store,
        private readonly string $uniqueLockKey,
    ) {
        parent::__construct($store);
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (in_array($method, ['lock', 'restoreLock'], true)
            && $this->getStore() instanceof LockProvider) {
            $parameters[0] = $this->uniqueLockKey;

            return $this->getStore()->{$method}(...$parameters);
        }

        return parent::__call($method, $parameters);
    }
}
