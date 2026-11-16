<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

it('fingerprints the rendered content, target, and driver', function () {
    $context = new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents');
    $target = new DocumentTarget('disk', $context);
    $rendered = new RenderedDocument('HTML', $target);
    $equivalent = new RenderedDocument('HTML', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoices/42.pdf', 'documents'),
    ));
    $differentSnapshots = [
        new RenderedDocument('different HTML', $target),
        new RenderedDocument('HTML', $target, 'other-driver'),
        new RenderedDocument('HTML', new DocumentTarget('other-storage', $context)),
        new RenderedDocument('HTML', new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/43.pdf', 'documents'))),
        new RenderedDocument('HTML', new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoices/42.pdf', 'other-disk'))),
    ];

    expect($equivalent->fingerprint())->toBe($rendered->fingerprint());

    foreach ($differentSnapshots as $different) {
        expect($different->fingerprint())->not->toBe($rendered->fingerprint());
    }
});

it('rejects an empty PDF driver identifier', function () {
    $target = new DocumentTarget('disk', new FilesystemDocumentStorageContext('invoice.pdf', 'documents'));

    expect(fn () => new RenderedDocument('HTML', $target, ''))
        ->toThrow(InvalidArgumentException::class, 'The document PDF driver configuration cannot be empty.');
});

it('serializes the rendered revision without changing its fingerprint', function () {
    $rendered = new RenderedDocument('frozen HTML', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ), 'driver-v1');
    $restored = unserialize(serialize($rendered));

    expect($restored)->toEqual($rendered)
        ->and($restored->fingerprint())->toBe($rendered->fingerprint())
        ->and((new ReflectionClass($restored))->isReadOnly())->toBeTrue();
});
