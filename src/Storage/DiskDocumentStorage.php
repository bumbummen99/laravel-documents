<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage;

use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use Throwable;

/**
 * Stores the PDF directly at the filesystem path, replacing the previous file.
 */
class DiskDocumentStorage implements Contracts\DocumentStorage
{
    public const int WRITE_LOCK_TIMEOUT = 10;

    public function __construct(
        /**
         * Seconds the write lock is held at most.
         */
        public int $lockTimeout = self::WRITE_LOCK_TIMEOUT,
    ) {
        //
    }

    public function write(DocumentStorageContext $context, string $contents): ?string
    {
        $context = FilesystemDocumentStorageContext::from($context);
        $store = Cache::store()->getStore();

        if (! $store instanceof LockProvider || $store instanceof NullStore) {
            throw new LogicException('Disk document storage requires a default cache store with atomic locks.');
        }

        $written = false;

        try {
            $store->lock(static::writeLockKey($context), $this->lockTimeout)->block($this->lockTimeout * 2, function () use ($context, $contents, &$written) {
                if (! Storage::disk($context->disk)->put($context->path, $contents)) {
                    throw new RuntimeException('Failed to write the generated PDF.');
                }

                $written = true;
            });
        } catch (Throwable $exception) {
            if (! $written) {
                throw $exception;
            }

            report($exception);
        }

        return null;
    }

    public function get(DocumentStorageContext $context, ?string $version = null): string
    {
        $this->ensureUnversioned($version);
        $context = FilesystemDocumentStorageContext::from($context);

        return Storage::disk($context->disk)->get($context->path)
            ?? throw new RuntimeException("The PDF file [{$context->path}] does not exist.");
    }

    public function exists(DocumentStorageContext $context, ?string $version = null): bool
    {
        $this->ensureUnversioned($version);
        $context = FilesystemDocumentStorageContext::from($context);

        return Storage::disk($context->disk)->exists($context->path);
    }

    public function versions(DocumentStorageContext $context): array
    {
        FilesystemDocumentStorageContext::from($context);

        return [];
    }

    public static function writeLockKey(FilesystemDocumentStorageContext $context): string
    {
        return static::class.':'.hash('sha256', $context->identity());
    }

    protected function ensureUnversioned(?string $version): void
    {
        if ($version !== null) {
            throw new InvalidArgumentException(static::class.' does not keep versions.');
        }
    }
}
