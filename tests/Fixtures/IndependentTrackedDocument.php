<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Mateffy\JobProgress\Attributes\Cancellable;
use Mateffy\JobProgress\Contracts\HasJobProgress;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\TracksDocumentProgress;

/**
 * @implements HasJobProgress<string|null>
 */
#[Cancellable]
final class IndependentTrackedDocument extends IndependentGenerateDocument implements HasJobProgress
{
    use TracksDocumentProgress;

    protected function documentBatchCancelled(): bool
    {
        return false;
    }
}
