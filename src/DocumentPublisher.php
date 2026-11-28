<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use LogicException;
use SkyRaptor\LaravelDocuments\Events\DispatchesDocumentEvents;
use SkyRaptor\LaravelDocuments\Events\DocumentRenderFailed;
use SkyRaptor\LaravelDocuments\Events\DocumentStored;
use SkyRaptor\LaravelDocuments\Events\DocumentSuperseded;
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
     * Superseded publication returns null without storing.
     */
    public function publish(GeneratedDocument $generated): ?string
    {
        return $this->publishWithResult($generated)->version;
    }

    /**
     * Store the generated contents and report whether publication was superseded.
     */
    public function publishWithResult(GeneratedDocument $generated): DocumentPublicationResult
    {
        return $this->publishAttempt($generated) ?? throw new LogicException('An explicit publication cannot be abandoned.');
    }

    public function publishCoalescedAttempt(GeneratedDocument $generated, string $token): void
    {
        $this->publishAttempt($generated, $token);
    }

    private function publishAttempt(GeneratedDocument $generated, ?string $workerToken = null): ?DocumentPublicationResult
    {
        $target = $generated->target;

        try {
            $storage = $this->storage->resolve($target->storage);
            $superseded = function (string $latestSnapshotId) use ($generated, $target): void {
                $this->dispatchDocumentEvent(new DocumentSuperseded(
                    $target,
                    $generated->generationId,
                    $generated->snapshotId,
                    $latestSnapshotId,
                ));
            };

            if (config('laravel-documents.coalescing.enabled')) {
                $outcome = app(DocumentCoalescer::class)->publish($generated, $storage, $workerToken);

                if ($outcome->status === CoalescedPublicationStatus::Abandoned) {
                    return null;
                }

                if ($outcome->status === CoalescedPublicationStatus::Superseded) {
                    $superseded($outcome->latestSnapshotId ?? throw new LogicException('A superseded publication must identify the latest snapshot.'));

                    return DocumentPublicationResult::superseded();
                }

                if ($outcome->status === CoalescedPublicationStatus::Reused) {
                    return DocumentPublicationResult::published($outcome->version);
                }
                $version = $outcome->version;
            } else {
                $version = $this->snapshots->publish(
                    $generated,
                    fn () => $storage->write($target->context, $generated->contents),
                    $superseded,
                );
            }
        } catch (Throwable $exception) {
            $this->dispatchDocumentEvent(new DocumentRenderFailed(
                target: $target,
                generationId: $generated->generationId,
                exceptionClass: $exception::class,
                message: $exception->getMessage(),
            ));

            throw $exception;
        }

        if ($version === false) {
            return DocumentPublicationResult::superseded();
        }

        $this->dispatchDocumentEvent(new DocumentStored($target, $generated->generationId, $version));

        return DocumentPublicationResult::published($version);
    }
}
