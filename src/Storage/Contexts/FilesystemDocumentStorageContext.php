<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage\Contexts;

use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;

final readonly class FilesystemDocumentStorageContext extends DocumentStorageContext
{
    public string $path;

    public string $disk;

    public function __construct(
        string $path,
        string $disk,
    ) {
        $path = (new WhitespacePathNormalizer)->normalizePath($path);

        if ($path === '' || $disk === '') {
            throw new InvalidArgumentException('A filesystem document context requires a disk and path.');
        }

        $this->path = $path;
        $this->disk = $disk;
    }

    public function identity(): string
    {
        return 'filesystem:'.strlen($this->disk).':'.$this->disk.$this->path;
    }

    public static function from(DocumentStorageContext $context): self
    {
        if (! $context instanceof self) {
            throw new InvalidArgumentException('Filesystem storage requires '.self::class.'.');
        }

        return $context;
    }
}
