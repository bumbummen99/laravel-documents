<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

final readonly class DocumentTargetEvent
{
    public function __construct(
        public int $id,
        public DocumentTargetEventType $type,
    ) {}
}
