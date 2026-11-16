<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Integrations\JobProgress;

use Mateffy\JobProgress\Traits\Progress;

/**
 * Uses Mateffy's progress error handling unchanged. Progress failures can fail
 * the job after the PDF is stored; queue retries may publish it again.
 */
trait TracksDocumentProgress
{
    /** @use Progress<string|null> */
    use Progress;

    abstract public function generationId(): string;

    abstract public function generate(): ?string;

    abstract protected function documentBatchCancelled(): bool;

    public function getProgressId(): string
    {
        return $this->generationId();
    }

    public function handleWithProgress(): void
    {
        if ($this->documentBatchCancelled()) {
            $this->progress()->exitIfCancelled()->cancelAndExit();
        }

        $version = $this->generate();
        $this->progress()->complete(result: $version);
    }
}
