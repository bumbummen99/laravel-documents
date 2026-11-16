<?php

declare(strict_types=1);

namespace App\Jobs;

use Mateffy\JobProgress\Attributes\Cancellable;
use SkyRaptor\LaravelDocuments\Integrations\JobProgress\GenerateVersionedDocumentWithProgress;

#[Cancellable]
class TrackedVersionedDocument extends GenerateVersionedDocumentWithProgress
{
    // Add application-specific progress behavior here.
}
