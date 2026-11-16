<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use SkyRaptor\LaravelDocuments\Events\DispatchesDocumentEvents;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use Throwable;

/**
 * Publishes already-generated PDF contents and records their fingerprint.
 */
class DocumentPublisher
{
    use DispatchesDocumentEvents;

    public function __construct(
        private readonly DocumentStorageManager $storage,
        private readonly DocumentSnapshotCache $snapshots,
    ) {}

    /**
     * Store the generated contents, returning their version for versioned storage.
     */
    public function publish(GeneratedDocument $generated): ?string
    {
        $target = $generated->target;

        try {
            $storage = $this->storage->resolve($target->storage);
            $version = $this->snapshots->publish(
                $generated,
                fn () => $storage->write($target->context, $generated->contents),
            );
        } catch (Throwable $exception) {
            $this->dispatchDocumentEvent(new DocumentRenderFailed(
                target: $target,
                generationId: $generated->generationId,
                exceptionClass: $exception::class,
                message: $exception->getMessage(),
            ));

            throw $exception;
        }

        $this->dispatchDocumentEvent(new DocumentStored($target, $generated->generationId, $version));

        return $version;
    }
}
