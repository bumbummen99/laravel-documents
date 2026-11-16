<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

trait HasPdfDriver
{
    public static string $pdfDriver = 'default';

    public function pdfDriver(): string
    {
        return static::$pdfDriver;
    }
}
