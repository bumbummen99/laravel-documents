<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

use LogicException;

trait HasPath
{
    /**
     * The path of the PDF file on the disk.
     */
    public static ?string $path = null;

    /**
     * The path of the PDF file on the disk.
     * Note: Overwrite this to apply custom logic. e.g. "invoices/{id}.pdf".
     */
    public function path(): string
    {
        if (static::$path === null || static::$path === '') {
            throw new LogicException('The document path must be configured or overridden.');
        }

        return static::$path;
    }
}
