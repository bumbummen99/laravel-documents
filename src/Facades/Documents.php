<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Facades;

use Illuminate\Support\Facades\Facade;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;

/**
 * @method static GeneratedDocument generate(Document|RenderedDocument $document, ?string $generationId = null)
 * @method static string|null publish(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId = null)
 * @method static GenerateDocument makeJob(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, class-string|null $job = null)
 * @method static GenerateDocument|null makeJobIfChanged(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, class-string|null $job = null)
 *
 * @see DocumentWorkflow
 */
class Documents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentWorkflow::class;
    }
}
