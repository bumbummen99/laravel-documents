<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;

trait HasJob
{
    /**
     * The queue job generating this document, or null to let the storage decide.
     *
     * @var class-string<GenerateDocument>|null
     */
    public static ?string $job = null;

    /**
     * Accessor for this Document's queue job.
     * Note: Overwrite this to apply custom logic.
     *
     * @return class-string<GenerateDocument>|null
     */
    public function job(): ?string
    {
        return static::$job;
    }
}
