<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use LogicException;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

/**
 * Only one job per storage-context identity can be queued or running at a time.
 * Further dispatches for that target are dropped until the job completes or fails.
 *
 * Unique jobs cannot be batched: Laravel does not acquire unique locks for
 * batched jobs, but would release the target's lock when they complete.
 */
class GenerateUniqueDocument extends AbstractGenerateDocument implements ShouldBeUnique
{
    /**
     * Reject batches, which would bypass and release the target's unique lock.
     */
    final public function withBatchId(string $batchId): never
    {
        throw new LogicException('Unique document jobs cannot be batched. Use '.GenerateVersionedDocument::class.' or dispatch the job individually.');
    }

    /**
     * Get the unique ID for the job, which is the render target.
     *
     * @see https://laravel.com/docs/13.x/queues#unique-jobs
     */
    final public function uniqueId(): string
    {
        return self::targetId($this->rendered->target);
    }

    /**
     * Use one cache lock namespace for this target across subclasses of this job.
     */
    final public function uniqueVia(): Repository
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof LockProvider || $store instanceof NullStore) {
            throw new LogicException('Unique document jobs require a default cache store with atomic locks.');
        }

        return new UniqueDocumentCacheRepository(
            $store,
            self::lockKey($this->rendered->target),
        );
    }

    private static function targetId(DocumentTarget $target): string
    {
        if ($target->context instanceof FilesystemDocumentStorageContext) {
            return $target->context->disk.':'.$target->context->path;
        }

        return $target->context->identity();
    }

    private static function lockKey(DocumentTarget $target): string
    {
        return 'laravel_unique_document:'.hash('xxh128', $target->context->identity());
    }
}
