<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SkyRaptor\LaravelDocuments\LaravelDocumentsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDocumentsServiceProvider::class,
        ];
    }
}
