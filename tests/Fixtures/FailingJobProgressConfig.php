<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Mateffy\JobProgress\Data\JobState;
use Mateffy\JobProgress\Data\JobStatus;
use Mateffy\JobProgress\JobProgressConfig;
use RuntimeException;

class FailingJobProgressConfig extends JobProgressConfig
{
    /**
     * @param  list<JobStatus>|null  $failWhen
     */
    public function __construct(
        private readonly ?array $failWhen,
        private readonly string $message,
        float $cancelThreshold,
    ) {
        parent::__construct(
            cache_store: 'array',
            cache_prefix: 'job-progress',
            cache_duration: 900,
            make_cache_key: null,
            make_global_cache_key: null,
            cancel_threshold: $cancelThreshold,
        );
    }

    public function saveJobProgress(JobState $progress): void
    {
        if ($this->failWhen === null || in_array($progress->status, $this->failWhen, true)) {
            throw new RuntimeException($this->message);
        }

        parent::saveJobProgress($progress);
    }
}
