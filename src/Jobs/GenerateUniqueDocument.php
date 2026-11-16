<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use LogicException;

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
        return hash('xxh128', $this->rendered->target->context->identity());
    }

    /**
     * Use one cache lock namespace for this target across subclasses of this job.
     */
    final public function uniqueVia(): Repository
    {
        return Cache::store(UniqueDocumentCacheRepository::STORE_NAME);
    }
}
