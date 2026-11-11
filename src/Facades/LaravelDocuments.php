<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SkyRaptor\LaravelDocuments\LaravelDocuments
 */
class LaravelDocuments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SkyRaptor\LaravelDocuments\LaravelDocuments::class;
    }
}
