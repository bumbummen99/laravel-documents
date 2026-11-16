<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage\Contracts;

use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;

/**
 * Persists generated PDFs using serializable, implementation-specific contexts.
 */
interface DocumentStorage
{
    /**
     * Store generated PDF contents.
     *
     * @return string|null The identifier of the stored version, or null if the storage is not versioned.
     */
    public function write(DocumentStorageContext $context, string $contents): ?string;

    /**
     * Get the contents of the current or the given version of the PDF file.
     */
    public function get(DocumentStorageContext $context, ?string $version = null): string;

    /**
     * Determine if the current or the given version of the PDF file exists.
     */
    public function exists(DocumentStorageContext $context, ?string $version = null): bool;

    /**
     * List the stored versions of the PDF file, from oldest to newest.
     *
     * @return list<string>
     */
    public function versions(DocumentStorageContext $context): array;
}
