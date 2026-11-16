<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

trait HasDisk
{
    /**
     * The name of the disk to store the document, or null for the default disk.
     */
    public static ?string $disk = null;

    /**
     * Accessor for this Document's disk name.
     * Note: Overwrite this to apply custom logic.
     */
    public function disk(): ?string
    {
        return static::$disk;
    }
}
