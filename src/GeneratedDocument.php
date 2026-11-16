<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use InvalidArgumentException;

/**
 * Final PDF contents and the frozen identity required to publish them.
 * No source HTML, document, or generator is retained.
 */
final readonly class GeneratedDocument
{
    public DocumentTarget $target;

    public string $fingerprint;

    public function __construct(
        RenderedDocument $rendered,
        public string $contents,
        public string $generationId,
    ) {
        if ($generationId === '') {
            throw new InvalidArgumentException('The generation ID cannot be empty.');
        }

        $this->target = $rendered->target;
        $this->fingerprint = $rendered->fingerprint();
    }
}
