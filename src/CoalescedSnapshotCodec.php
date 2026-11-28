<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;

/** @internal */
final class CoalescedSnapshotCodec
{
    public function encode(RenderedDocument $rendered): string
    {
        $context = FilesystemDocumentStorageContext::from($rendered->target->context);

        return 'encrypted:v1:'.Crypt::encryptString(json_encode([
            'html' => $rendered->html,
            'storage' => $rendered->target->storage,
            'path' => $context->path,
            'disk' => $context->disk,
            'driver' => $rendered->driver,
            'snapshot' => $rendered->snapshotId,
            'metadata' => ($rendered->metadata ?? null)?->toArray(),
            'generator' => $rendered->generatorClass(),
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(string $payload): RenderedDocument
    {
        if (str_starts_with($payload, 'encrypted:v1:')) {
            $payload = Crypt::decryptString(substr($payload, strlen('encrypted:v1:')));
        }
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! isset($data['html'], $data['storage'], $data['path'], $data['disk'], $data['driver'], $data['snapshot'])
            || ! is_string($data['html']) || ! is_string($data['storage']) || ! is_string($data['path'])
            || ! is_string($data['disk']) || ! is_string($data['driver']) || ! is_string($data['snapshot'])) {
            throw new RuntimeException('The stored coalesced document snapshot is invalid.');
        }

        if (array_key_exists('metadata', $data) && $data['metadata'] !== null && ! is_array($data['metadata'])) {
            throw new RuntimeException('The stored coalesced document metadata is invalid.');
        }
        $generator = array_key_exists('generator', $data) ? $data['generator'] : DocumentGenerator::class;

        if (! is_string($generator) || ! is_a($generator, DocumentGenerator::class, true)) {
            throw new RuntimeException('The stored coalesced document generator is invalid or unavailable.');
        }

        return new RenderedDocument(
            $data['html'],
            new DocumentTarget($data['storage'], new FilesystemDocumentStorageContext($data['path'], $data['disk'])),
            $data['driver'],
            latestSnapshotWins: true,
            registeredSnapshotId: $data['snapshot'],
            metadata: isset($data['metadata']) ? DocumentMetadata::fromArray($data['metadata']) : null,
            generator: $generator,
        );
    }
}
