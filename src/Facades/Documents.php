<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Facades;

use Illuminate\Support\Facades\Facade;
use SkyRaptor\LaravelDocuments\DocumentPublicationResult;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\DocumentTargetEvent;
use SkyRaptor\LaravelDocuments\DocumentTargetStatus;
use SkyRaptor\LaravelDocuments\DocumentWorkflow;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;

/**
 * @method static GeneratedDocument generate(Document|RenderedDocument $document, ?string $generationId = null)
 * @method static string|null publish(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId = null)
 * @method static DocumentPublicationResult publishWithResult(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId = null)
 * @method static DocumentPublicationResult publishIfChanged(Document|RenderedDocument $document)
 * @method static DocumentTargetStatus targetStatus(DocumentTarget $target)
 * @method static int targetEventCursor(DocumentTarget $target)
 * @method static list<DocumentTargetEvent> targetEvents(DocumentTarget $target, int $after = 0)
 * @method static GenerateDocument makeJob(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, class-string|null $job = null)
 * @method static GenerateDocument|null makeJobIfChanged(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, class-string|null $job = null)
 * @method static bool dispatchCoalesced(RenderedDocument $document, ?string $connection = null, ?string $queue = null)
 * @method static void invalidate(DocumentTarget $target)
 * @method static void tombstoneTarget(DocumentTarget $target)
 * @method static void retireTarget(DocumentTarget $target)
 * @method static void restoreTarget(DocumentTarget $target)
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
