<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;

/**
 * Identifies a registered storage configuration and its document-specific context.
 */
final readonly class DocumentTarget
{
    public function __construct(
        public string $storage,
        public DocumentStorageContext $context,
    ) {
        if ($storage === '' || $context->identity() === '') {
            throw new InvalidArgumentException('The document storage configuration and target identity cannot be empty.');
        }
    }
}
