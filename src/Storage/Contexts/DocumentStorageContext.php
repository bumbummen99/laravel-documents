<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage\Contexts;

/**
 * Serializable, storage-specific parameters identifying one document target.
 */
abstract readonly class DocumentStorageContext
{
    /**
     * A stable, unambiguous identity for the underlying storage target.
     */
    abstract public function identity(): string;
}
