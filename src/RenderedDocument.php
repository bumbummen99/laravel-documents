<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use InvalidArgumentException;

/**
 * An immutable revision of a document: its rendered
 * HTML, storage context, and configuration identifiers. No document, driver,
 * or storage implementation is serialized into the rendered result.
 */
final readonly class RenderedDocument
{
    public function __construct(
        /**
         * The rendered HTML of the document.
         */
        public string $html,
        /**
         * Where and how the PDF file is stored.
         */
        public DocumentTarget $target,
        /**
         * The registered PDF driver configuration.
         */
        public string $driver = 'default',
    ) {
        if ($driver === '') {
            throw new InvalidArgumentException('The document PDF driver configuration cannot be empty.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize([$this->html, $this->target, $this->driver]));
    }
}
