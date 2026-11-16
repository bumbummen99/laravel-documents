<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\Storage\Contexts\DocumentStorageContext;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;

it('requires both a storage configuration and a target identity', function (string $storage, bool $emptyIdentity) {
    $context = $emptyIdentity ? new readonly class extends DocumentStorageContext
    {
        public function identity(): string
        {
            return '';
        }
    } : new RecordStorageContext(42);

    expect(fn () => new DocumentTarget($storage, $context))
        ->toThrow(InvalidArgumentException::class, 'The document storage configuration and target identity cannot be empty.');
})->with([
    'missing storage' => ['', false],
    'missing identity' => ['records', true],
    'both missing' => ['', true],
]);
