<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\Events\DispatchesDocumentEvents;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentRendering;
use SkyRaptor\LaravelDocuments\Pdf\DocumentPdfDriverManager;
use Throwable;

/**
 * Generates final PDF bytes from frozen HTML without storing or scheduling them.
 */
class DocumentGenerator
{
    use DispatchesDocumentEvents;

    public function __construct(private readonly DocumentPdfDriverManager $drivers) {}

    /**
     * Generate once and freeze the final PDF contents and their publication identity.
     */
    public function generate(RenderedDocument $rendered, ?string $generationId = null): GeneratedDocument
    {
        if ($generationId === '') {
            throw new InvalidArgumentException('The generation ID cannot be empty.');
        }

        $generationId ??= (string) Str::uuid();
        $this->dispatchDocumentEvent(new DocumentRendering($rendered->target, $generationId));

        try {
            return new GeneratedDocument($rendered, $this->generateContents($rendered), $generationId);
        } catch (Throwable $exception) {
            $this->dispatchDocumentEvent(new DocumentRenderFailed(
                target: $rendered->target,
                generationId: $generationId,
                exceptionClass: $exception::class,
                message: $exception->getMessage(),
            ));

            throw $exception;
        }
    }

    /**
     * Customize PDF generation without changing result identity or lifecycle events.
     */
    protected function generateContents(RenderedDocument $rendered): string
    {
        $builder = $this->drivers
            ->builder($rendered->driver)
            ->html($rendered->html);

        if (($rendered->metadata ?? null) !== null) {
            $builder->meta(...$rendered->metadata->toArray());
        }

        return $builder->generatePdfContent();
    }
}
