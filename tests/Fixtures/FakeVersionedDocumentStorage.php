<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;

class FakeVersionedDocumentStorage implements DocumentStorage
{
    public function write(DocumentStorageContext $context, string $contents): ?string
    {
        $context = FilesystemDocumentStorageContext::from($context);
        $version = (string) Str::uuid();

        if (! Storage::disk($context->disk)->put($context->path.'/'.$version.'.pdf', $contents)) {
            throw new RuntimeException('Could not write the test document.');
        }

        return $version;
    }

    public function get(DocumentStorageContext $context, ?string $version = null): string
    {
        $context = FilesystemDocumentStorageContext::from($context);

        if ($version === null) {
            throw new LogicException('The test storage requires a version.');
        }

        return Storage::disk($context->disk)->get($context->path.'/'.$version.'.pdf')
            ?? throw new RuntimeException('The test document does not exist.');
    }

    public function exists(DocumentStorageContext $context, ?string $version = null): bool
    {
        $context = FilesystemDocumentStorageContext::from($context);

        return $version === null
            ? $this->versions($context) !== []
            : Storage::disk($context->disk)->exists($context->path.'/'.$version.'.pdf');
    }

    public function versions(DocumentStorageContext $context): array
    {
        $context = FilesystemDocumentStorageContext::from($context);

        return array_map(
            fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            Storage::disk($context->disk)->files($context->path),
        );
    }
}
