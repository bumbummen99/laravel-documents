<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\RenderedDocument;

final class HtmlDocumentGenerator extends DocumentGenerator
{
    protected function generateContents(RenderedDocument $rendered): string
    {
        return $rendered->html;
    }
}
