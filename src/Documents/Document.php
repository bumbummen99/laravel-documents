<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents;

use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;

/**
 * The Document is the central definition of a document as a first-class citizen.
 * It controls how the document is composed & accessed, especially:
 * - How data is loaded
 * - What view is rendered
 * - Where and how the PDF file is stored
 * - Rendering immutable HTML for PDF generation
 * - How stored PDF files are accessed
 *
 * Static properties configure the document type, while an instance is bound to
 * the subject being rendered, e.g. an invoice passed to the constructor.
 */
abstract class Document
{
    use Concerns\HasDisk;
    use Concerns\HasJob;
    use Concerns\HasPath;
    use Concerns\HasPdfDriver;
    use Concerns\HasStorage;
    use Concerns\HasView;

    /**
     * Where and how the PDF file is stored. Does not load data or render.
     */
    public function target(): DocumentTarget
    {
        return new DocumentTarget(
            storage: $this->storage(),
            context: $this->storageContext(),
        );
    }

    /**
     * Supply document-specific target parameters without resolving storage.
     */
    public function storageContext(): DocumentStorageContext
    {
        return new FilesystemDocumentStorageContext(
            path: $this->path(),
            disk: $this->disk() ?? Storage::getDefaultDriver(),
        );
    }

    /**
     * Capture the current revision of the document by rendering its HTML.
     * This loads the document's data and may be expensive.
     */
    public function render(): RenderedDocument
    {
        return new RenderedDocument(
            html: $this->html(),
            target: $this->target(),
            driver: $this->pdfDriver(),
        );
    }

    /**
     * Get the contents of the current or the given version of the stored PDF file.
     */
    public function get(?string $version = null): string
    {
        $target = $this->target();

        return app(DocumentStorageManager::class)->resolve($target->storage)->get($target->context, $version);
    }

    /**
     * Determine if the current or the given version of the PDF file is stored.
     */
    public function exists(?string $version = null): bool
    {
        $target = $this->target();

        return app(DocumentStorageManager::class)->resolve($target->storage)->exists($target->context, $version);
    }

    /**
     * List the stored versions of the PDF file, from oldest to newest.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        $target = $this->target();

        return app(DocumentStorageManager::class)->resolve($target->storage)->versions($target->context);
    }
}
