<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\DocumentGenerator;
use SkyRaptor\LaravelDocuments\DocumentPublisher;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;

class IndependentGenerateDocument implements GenerateDocument
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private string $id;

    /** @var class-string<DocumentGenerator> */
    private string $generator = DocumentGenerator::class;

    public function __construct(private readonly RenderedDocument $rendered)
    {
        $this->id = (string) Str::uuid();
    }

    public function generationId(): string
    {
        return $this->id;
    }

    public function withGenerator(string $generator): static
    {
        if (! is_a($generator, DocumentGenerator::class, true)) {
            throw new InvalidArgumentException('Invalid generator.');
        }

        $this->generator = $generator;

        return $this;
    }

    public function configureQueue(?string $connection, ?string $queue): static
    {
        $this->onConnection($connection);
        $this->onQueue($queue);

        return $this;
    }

    public function generate(): ?string
    {
        $generated = app($this->generator)->generate($this->rendered, $this->id);

        return app(DocumentPublisher::class)->publish($generated);
    }

    public function handle(): void
    {
        $this->generate();
    }
}
