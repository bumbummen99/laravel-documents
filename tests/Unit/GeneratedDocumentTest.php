<?php

declare(strict_types=1);

use SkyRaptor\LaravelDocuments\DocumentTarget;
use SkyRaptor\LaravelDocuments\GeneratedDocument;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

it('freezes the generated contents and publication identity without retaining rendered HTML', function () {
    $rendered = new RenderedDocument('private rendered HTML', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ), 'driver-v1');
    $generated = new GeneratedDocument($rendered, 'final PDF', 'generation-1');

    expect($generated->contents)->toBe('final PDF')
        ->and($generated->target)->toBe($rendered->target)
        ->and($generated->fingerprint)->toBe($rendered->fingerprint())
        ->and($generated->generationId)->toBe('generation-1')
        ->and(unserialize(serialize($generated)))->toEqual($generated)
        ->and(serialize($generated))->not->toContain('private rendered HTML', RenderedDocument::class)
        ->and((new ReflectionClass($generated))->isReadOnly())->toBeTrue()
        ->and(fn () => $generated->contents = 'changed')->toThrow(Error::class)
        ->and(fn () => $generated->generationId = 'changed')->toThrow(Error::class)
        ->and(fn () => $generated->target = $rendered->target)->toThrow(Error::class)
        ->and(fn () => $generated->fingerprint = 'changed')->toThrow(Error::class);
});

it('rejects a generation result without a generation identity', function () {
    $rendered = new RenderedDocument('HTML', new DocumentTarget(
        'disk',
        new FilesystemDocumentStorageContext('invoice.pdf', 'documents'),
    ));

    expect(fn () => new GeneratedDocument($rendered, 'PDF', ''))
        ->toThrow(InvalidArgumentException::class, 'The generation ID cannot be empty.');
});
