<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Events;

use SkyRaptor\LaravelDocuments\DocumentTarget;
use Throwable;

final readonly class DocumentRenderFailed
{
    /**
     * @param  class-string<Throwable>  $exceptionClass
     */
    public function __construct(
        public DocumentTarget $target,
        public string $generationId,
        public string $exceptionClass,
        public string $message,
    ) {}
}
