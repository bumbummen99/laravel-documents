<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

class GenerateDocumentFailingWithMissingModels extends GenerateDocumentWithRelatedModel
{
    public bool $deleteWhenMissingModels = false;
}
