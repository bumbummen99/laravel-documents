<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use RuntimeException;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\RenderedDocument;

class FailingDocumentGenerator extends DocumentGenerator
{
    protected function generateContents(RenderedDocument $rendered): string
    {
        throw new RuntimeException('PDF generation failed');
    }
}
