<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Events;

use SkyRaptor\LaravelDocuments\DocumentTarget;

final readonly class DocumentSuperseded
{
    public function __construct(
        public DocumentTarget $target,
        public string $generationId,
        public string $snapshotId,
        public string $latestSnapshotId,
    ) {}
}
