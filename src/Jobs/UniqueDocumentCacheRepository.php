<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use InvalidArgumentException;

/**
 * Routes framework unique-job locks for a document job to its target key,
 * rather than including the concrete job class in the key.
 *
 * @internal
 */
final class UniqueDocumentCacheRepository extends Repository
{
    public const string STORE_NAME = 'laravel-documents-unique';

    /**
     * @param  Closure(): Store  $resolveStore
     */
    public function __construct(private readonly Closure $resolveStore)
    {
        parent::__construct($this->getStore(), ['store' => self::STORE_NAME]);
    }

    public function getStore(): Store
    {
        return $this->store = ($this->resolveStore)();
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call(mixed $method, mixed $parameters): mixed
    {
        $store = $this->getStore();

        if (in_array($method, ['lock', 'restoreLock'], true)
            && $store instanceof LockProvider) {
            $parameters[0] = $this->lockKey($parameters[0]);

            return $store->{$method}(...$parameters);
        }

        return parent::__call($method, $parameters);
    }

    private function lockKey(string $frameworkKey): string
    {
        if (preg_match('/\Alaravel_unique_job:.+:([a-f0-9]{32})\z/s', $frameworkKey, $matches) !== 1) {
            throw new InvalidArgumentException('Expected a Laravel unique-job lock key with a document context identity.');
        }

        return 'laravel_unique_document:'.$matches[1];
    }
}
