<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Tests\Fixtures\RecordStorageContext;

it('requires a non-empty normalized path and disk', function (string $path, string $disk) {
    expect(fn () => new FilesystemDocumentStorageContext($path, $disk))
        ->toThrow(InvalidArgumentException::class, 'A filesystem document context requires a disk and path.');
})->with([
    'missing path' => ['', 'documents'],
    'missing disk' => ['invoice.pdf', ''],
    'empty normalized path' => ['invoices/..', 'documents'],
]);

it('keeps a stable filesystem identity across normalization and serialization', function () {
    $context = new FilesystemDocumentStorageContext('/invoices//2026/../42.pdf', 'documents');
    $restored = unserialize(serialize($context));

    expect($context->path)->toBe('invoices/42.pdf')
        ->and($context->identity())->toBe('filesystem:9:documentsinvoices/42.pdf')
        ->and($restored->identity())->toBe($context->identity())
        ->and(FilesystemDocumentStorageContext::from($context))->toBe($context);
});

it('distinguishes disks and paths whose concatenated names would otherwise collide', function () {
    $first = new FilesystemDocumentStorageContext('bc.pdf', 'a');
    $second = new FilesystemDocumentStorageContext('c.pdf', 'ab');

    expect($first->identity())->not->toBe($second->identity());
});

it('rejects a non-filesystem context with an actionable error', function () {
    expect(fn () => FilesystemDocumentStorageContext::from(new RecordStorageContext(42)))
        ->toThrow(InvalidArgumentException::class, 'Filesystem storage requires '.FilesystemDocumentStorageContext::class.'.');
});
