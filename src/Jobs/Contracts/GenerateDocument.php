<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use SkyRaptor\LaravelDocuments\RenderedDocument;

interface GenerateDocument extends ShouldQueue
{
    public function __construct(RenderedDocument $rendered);

    public function generationId(): string;

    public function generate(): ?string;

    /**
     * @param  class-string  $generator
     */
    public function withGenerator(string $generator): static;

    public function configureQueue(?string $connection, ?string $queue): static;
}
