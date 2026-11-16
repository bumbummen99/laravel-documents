<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

/**
 * Stores every write as a new version using skyraptor/laravel-versioned-storage.
 * Each version is a directory snapshot holding the PDF file.
 *
 * Requires the optional skyraptor/laravel-versioned-storage package.
 */
class VersionedDocumentStorage implements Contracts\DocumentStorage
{
    public const string DOCUMENT_FILE = 'document.pdf';

    public function __construct(
        /**
         * Retire all previous versions when a new version is published.
         */
        public bool $cleanup = false,
    ) {
        if (! class_exists(VersionedStorage::class)) {
            throw new LogicException(static::class.' requires the skyraptor/laravel-versioned-storage package.');
        }
    }

    public function write(DocumentStorageContext $context, string $contents): ?string
    {
        return $this->handler($context)->write(function (FilesystemAdapter $files) use ($contents) {
            if (! $files->put(static::DOCUMENT_FILE, $contents)) {
                throw new RuntimeException('Failed to write the generated PDF.');
            }
        }, $this->cleanup);
    }

    public function get(DocumentStorageContext $context, ?string $version = null): string
    {
        return $this->handler($context)->get(static::DOCUMENT_FILE, $version);
    }

    public function exists(DocumentStorageContext $context, ?string $version = null): bool
    {
        return $this->handler($context)->exists(static::DOCUMENT_FILE, $version);
    }

    public function versions(DocumentStorageContext $context): array
    {
        return $this->handler($context)->versions();
    }

    protected function handler(DocumentStorageContext $context): VersionedStorageHandler
    {
        $context = FilesystemDocumentStorageContext::from($context);
        $handler = VersionedStorage::directory($context->path, disk: $context->disk);

        if (! $handler instanceof VersionedStorageHandler) {
            throw new LogicException('Expected a versioned storage handler.');
        }

        return $handler;
    }
}
