<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\DocumentSnapshotCache;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;

/**
 * Creates snapshot jobs without generating PDFs or dispatching work.
 */
final class DocumentJobFactory
{
    public function __construct(
        private readonly DocumentStorageManager $storage,
        private readonly DocumentSnapshotCache $snapshots,
    ) {}

    /**
     * Use the given job class or the target storage's registered default.
     *
     * @param  class-string|null  $job
     */
    public function make(RenderedDocument $rendered, ?string $connection = null, ?string $queue = null, ?string $job = null): GenerateDocument
    {
        $job ??= $this->storage->job($rendered->target->storage);

        if (! is_a($job, GenerateDocument::class, true)) {
            throw new InvalidArgumentException('The job must implement '.GenerateDocument::class.'.');
        }

        return (new $job($rendered))->configureQueue($connection, $queue);
    }

    /**
     * Create a job only when the snapshot differs from the last cached publication.
     *
     * @param  class-string|null  $job
     */
    public function makeIfChanged(RenderedDocument $rendered, ?string $connection = null, ?string $queue = null, ?string $job = null): ?GenerateDocument
    {
        return $this->snapshots->changed($rendered)
            ? $this->make($rendered, $connection, $queue, $job)
            : null;
    }
}
