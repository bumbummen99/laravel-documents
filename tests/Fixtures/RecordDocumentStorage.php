<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;

final class RecordDocumentStorage implements DocumentStorage
{
    /** @var array<int, string> */
    private array $records = [];

    private Closure $nonSerializable;

    public function __construct()
    {
        $this->nonSerializable = fn () => null;
    }

    public function write(DocumentStorageContext $context, string $contents): ?string
    {
        $this->records[$this->recordId($context)] = $contents;

        return null;
    }

    public function get(DocumentStorageContext $context, ?string $version = null): string
    {
        $this->checkVersion($version);

        return $this->records[$this->recordId($context)]
            ?? throw new RuntimeException('The document record does not exist.');
    }

    public function exists(DocumentStorageContext $context, ?string $version = null): bool
    {
        $this->checkVersion($version);

        return array_key_exists($this->recordId($context), $this->records);
    }

    public function versions(DocumentStorageContext $context): array
    {
        $this->recordId($context);

        return [];
    }

    private function recordId(DocumentStorageContext $context): int
    {
        if (! $context instanceof RecordStorageContext) {
            throw new InvalidArgumentException('Record storage requires a record context.');
        }

        return $context->recordId;
    }

    private function checkVersion(?string $version): void
    {
        if ($version !== null) {
            throw new InvalidArgumentException('Record storage does not keep versions.');
        }
    }
}
