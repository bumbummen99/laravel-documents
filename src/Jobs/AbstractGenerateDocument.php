<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;

/**
 * Generates a PDF from a RenderedDocument and publishes the result.
 *
 * This base job makes no uniqueness guarantee. Concrete jobs decide whether
 * the same target may be queued multiple times, matching their storage.
 *
 * Custom jobs can call generate() from their own handler to integrate progress
 * tracking. If they replace handle(), they must check batch cancellation themselves.
 *
 * @see GenerateUniqueDocument
 * @see GenerateVersionedDocument
 */
abstract class AbstractGenerateDocument implements GenerateDocument
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @var class-string<DocumentGenerator>
     */
    protected string $generatorClass = DocumentGenerator::class;

    private ?string $generationId = null;

    private bool $generated = false;

    private ?string $version = null;

    /**
     * Seconds the worker lets the job run at most. Must stay below the queue's retry_after.
     */
    public int $timeout = 60;

    public function __construct(
        /**
         * The frozen input to PDF generation.
         */
        protected RenderedDocument $rendered,
    ) {
        $this->generationId = (string) Str::uuid();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->documentBatchCancelled()) {
            return;
        }

        $this->generate();
    }

    /**
     * Determine if this job instance has stored the document.
     */
    public function generated(): bool
    {
        return $this->generated;
    }

    /**
     * The version stored by this job instance, or null if the storage is not versioned
     * or the document has not been stored yet.
     */
    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * Identify this generation independently of its storage target.
     */
    public function generationId(): string
    {
        return $this->generationId ??= $this->job?->uuid() ?? (string) Str::uuid();
    }

    /**
     * Select the generator class to re-create when the job runs.
     *
     * @param  class-string  $generator
     */
    public function withGenerator(string $generator): static
    {
        if (! is_a($generator, DocumentGenerator::class, true)) {
            throw new InvalidArgumentException('The generator must extend '.DocumentGenerator::class.'.');
        }

        $this->generatorClass = $generator;

        return $this;
    }

    public function configureQueue(?string $connection, ?string $queue): static
    {
        $this->onConnection($connection);
        $this->onQueue($queue);

        return $this;
    }

    /**
     * Generate once and publish those PDF contents to the target's storage.
     *
     * @return string|null The identifier of the stored version, or null if the storage is not versioned.
     */
    public function generate(): ?string
    {
        $generated = $this->generator()->generate($this->rendered, $this->generationId());
        $this->version = app(DocumentPublisher::class)->publish($generated);
        $this->generated = true;

        return $this->version;
    }

    /**
     * Resolve the generator in the worker, including its container dependencies.
     */
    protected function generator(): DocumentGenerator
    {
        return app($this->generatorClass);
    }

    protected function documentBatchCancelled(): bool
    {
        return $this->batch()?->cancelled() ?? false;
    }
}
