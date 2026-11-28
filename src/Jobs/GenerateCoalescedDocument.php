<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;
use Throwable;

final class GenerateCoalescedDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $targetKey, public readonly string $token) {}

    public function handle(DocumentCoalescer $coalescer): void
    {
        try {
            $coalescer->work($this->targetKey, $this->token);
        } catch (Throwable $exception) {
            try {
                $coalescer->recordFailedAttempt($this->targetKey, $this->token);
            } catch (Throwable $recordingFailure) {
                report($recordingFailure);
            }

            throw $exception;
        }
    }
}
