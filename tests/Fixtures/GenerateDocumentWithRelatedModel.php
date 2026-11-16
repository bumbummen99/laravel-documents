<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;

class GenerateDocumentWithRelatedModel extends GenerateUniqueDocument
{
    use SerializesModels;

    public ?Model $relatedModel = null;

    public bool $deleteWhenMissingModels = true;
}
