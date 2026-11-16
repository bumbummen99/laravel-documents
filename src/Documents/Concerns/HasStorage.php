<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

trait HasStorage
{
    /**
     * The registered storage configuration used to write and read the PDF.
     */
    public static string $storage = 'disk';

    /**
     * The storage configuration identifier.
     */
    public function storage(): string
    {
        return static::$storage;
    }
}
