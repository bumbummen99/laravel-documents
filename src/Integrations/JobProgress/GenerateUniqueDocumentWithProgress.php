<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Integrations\JobProgress;

use Mateffy\JobProgress\Attributes\Cancellable;
use Mateffy\JobProgress\Contracts\HasJobProgress;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;

/**
 * @implements HasJobProgress<string|null>
 */
#[Cancellable]
class GenerateUniqueDocumentWithProgress extends GenerateUniqueDocument implements HasJobProgress
{
    use TracksDocumentProgress;
}
