<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Integrations\JobProgress;

use Mateffy\JobProgress\Attributes\Cancellable;
use Mateffy\JobProgress\Contracts\HasJobProgress;
use SkyRaptor\LaravelDocuments\Jobs\GenerateVersionedDocument;

/**
 * @implements HasJobProgress<string|null>
 */
#[Cancellable]
class GenerateVersionedDocumentWithProgress extends GenerateVersionedDocument implements HasJobProgress
{
    use TracksDocumentProgress;
}
