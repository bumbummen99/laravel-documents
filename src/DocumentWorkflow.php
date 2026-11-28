<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\DocumentJobFactory;

/**
 * Completes only the missing stages of a document's lifecycle.
 */
class DocumentWorkflow
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly DocumentPublisher $publisher,
        private readonly DocumentJobFactory $jobs,
    ) {}

    /**
     * Render if necessary and generate final PDF contents without storing them.
     */
    public function generate(Document|RenderedDocument $document, ?string $generationId = null): GeneratedDocument
    {
        return $this->generator->generate(
            $document instanceof Document ? $document->render() : $document,
            $generationId,
        );
    }

    /**
     * Complete missing stages and store the PDF, returning its version if versioned.
     */
    public function publish(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId = null): ?string
    {
        return $this->publisher->publish($this->prepareForPublication($document, $generationId));
    }

    /**
     * Complete missing stages and report whether the PDF was published.
     */
    public function publishWithResult(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId = null): DocumentPublicationResult
    {
        return $this->publisher->publishWithResult($this->prepareForPublication($document, $generationId));
    }

    public function publishIfChanged(Document|RenderedDocument $document): DocumentPublicationResult
    {
        $rendered = $document instanceof Document ? $document->render() : $document;
        $existing = app(DocumentCoalescer::class)->existingPublication($rendered);

        return $existing ?? $this->publishWithResult($rendered);
    }

    private function prepareForPublication(Document|RenderedDocument|GeneratedDocument $document, ?string $generationId): GeneratedDocument
    {
        if ($document instanceof GeneratedDocument) {
            if ($generationId !== null && $generationId !== $document->generationId) {
                throw new InvalidArgumentException('A generated document already has a generation ID; it cannot be replaced.');
            }

            return $document;
        }

        return $this->generate($document, $generationId);
    }

    /**
     * Capture the document and create a job without dispatching it.
     *
     * @param  class-string|null  $job
     */
    public function makeJob(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, ?string $job = null): GenerateDocument
    {
        return $this->jobs->make(
            $document instanceof Document ? $document->render() : $document,
            $connection,
            $queue,
            $job ?? ($document instanceof Document ? $document->job() : null),
        );
    }

    /**
     * Create a job only when the captured snapshot differs from the last publication.
     *
     * @param  class-string|null  $job
     */
    public function makeJobIfChanged(Document|RenderedDocument $document, ?string $connection = null, ?string $queue = null, ?string $job = null): ?GenerateDocument
    {
        return $this->jobs->makeIfChanged(
            $document instanceof Document ? $document->render() : $document,
            $connection,
            $queue,
            $job ?? ($document instanceof Document ? $document->job() : null),
        );
    }

    public function dispatchCoalesced(RenderedDocument $document, ?string $connection = null, ?string $queue = null): bool
    {
        return app(DocumentCoalescer::class)->dispatch($document, $connection, $queue);
    }

    public function invalidate(DocumentTarget $target): void
    {
        app(DocumentCoalescer::class)->invalidate($target);
    }

    public function targetStatus(DocumentTarget $target): DocumentTargetStatus
    {
        return app(DocumentCoalescer::class)->status($target);
    }

    public function tombstoneTarget(DocumentTarget $target): void
    {
        app(DocumentCoalescer::class)->tombstone($target);
    }

    public function restoreTarget(DocumentTarget $target): void
    {
        app(DocumentCoalescer::class)->restore($target);
    }
}
