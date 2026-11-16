<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;

final readonly class RecordStorageContext extends DocumentStorageContext
{
    public function __construct(public int $recordId) {}

    public function identity(): string
    {
        return 'records:'.$this->recordId;
    }
}
