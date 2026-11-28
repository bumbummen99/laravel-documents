<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use ArrayObject;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Jobs\GenerateCoalescedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use stdClass;
use Throwable;

/**
 * Coordinates ordered publication and recoverable, at-least-once queue wakeups.
 */
final class DocumentCoalescer
{
    public function __construct(
        private readonly DocumentPublisher $publisher,
        private readonly DocumentStorageManager $storage,
        private readonly CoalescedSnapshotCodec $codec,
        private readonly CoalescedStageRecovery $stages,
    ) {}

    /**
     * Load and render only after observing the current target revision. If another
     * request registers a revision in the meantime, leave that revision untouched.
     *
     * @param  Closure(): Document  $load
     */
    public function captureIfUnchanged(DocumentTarget $target, Closure $load): ?RenderedDocument
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($target);
        $observed = $connection->table('document_coalescing_targets')->useWritePdo()->where('target_key', $key)->first();

        if ($observed?->tombstoned) {
            return null;
        }

        $document = $load();
        $actual = $document->target();

        if ($actual->storage !== $target->storage || $actual->context->identity() !== $target->context->identity()) {
            throw new LogicException('The loaded document must have the observed target.');
        }

        if (($document->latestSnapshotWins() ?? config('laravel-documents.latest_snapshot_wins', false)) !== true) {
            throw new LogicException('Conditional capture requires latest snapshot ordering.');
        }

        $html = $document->html();
        $driver = $document->pdfDriver();
        $metadata = $document->metadata();
        $generator = $document->generator();
        $fingerprint = RenderedDocument::fingerprintFor($html, $target, $driver, $metadata, $generator);

        return MetadataTransaction::run($connection, function () use ($connection, $key, $observed, $target, $html, $driver, $metadata, $generator, $fingerprint): ?RenderedDocument {
            $row = $this->lockTarget($connection, $key);

            if ($row->tombstoned || $row->latest_snapshot !== $observed?->latest_snapshot) {
                return null;
            }

            $snapshot = $row->latest_fingerprint === $fingerprint && is_string($row->latest_snapshot)
                ? $row->latest_snapshot
                : (string) Str::uuid();
            $rendered = new RenderedDocument($html, $target, $driver, latestSnapshotWins: true, registeredSnapshotId: $snapshot, metadata: $metadata, generator: $generator);

            if ($row->latest_snapshot === $snapshot
                && ($row->pending !== null || (
                    is_string($row->published_version)
                    && $row->published_fingerprint === $fingerprint
                    && $this->storage->resolve($target->storage)->exists($target->context, $row->published_version)
                ))) {
                return $rendered;
            }

            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'latest_snapshot' => $snapshot,
                'latest_fingerprint' => $fingerprint,
                'pending' => $this->codec->encode($rendered),
                'worker_token' => $row->worker_token ?? (string) Str::uuid(),
                'lease_until' => $row->worker_token === null ? null : $row->lease_until,
                'queue_connection' => $row->worker_token === null ? null : $row->queue_connection,
                'queue_name' => $row->worker_token === null ? null : $row->queue_name,
            ]);

            return $rendered;
        }, 5);
    }

    public function publishedVersion(RenderedDocument $rendered): ?string
    {
        $this->assertEnabled();
        $this->assertTarget($rendered->target);

        if ($rendered->snapshotId === null) {
            throw new LogicException('Publication inspection requires an ordered rendered snapshot.');
        }

        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($rendered->target);
        $row = $connection->table('document_coalescing_targets')->useWritePdo()->where('target_key', $key)->first();

        if ($row === null || $row->tombstoned || $row->latest_snapshot !== $rendered->snapshotId
            || $row->latest_fingerprint !== $rendered->fingerprint()
            || $row->published_fingerprint !== $rendered->fingerprint() || ! is_string($row->published_version)) {
            return null;
        }

        $version = $row->published_version;

        if (! $this->storage->resolve($rendered->target->storage)->exists($rendered->target->context, $version)) {
            return null;
        }
        $current = $connection->table('document_coalescing_targets')->useWritePdo()->where('target_key', $key)->first();

        return $current !== null && ! $current->tombstoned && $current->latest_snapshot === $rendered->snapshotId
            && $current->latest_fingerprint === $rendered->fingerprint()
            && $current->published_fingerprint === $rendered->fingerprint() && $current->published_version === $version
                ? $version
                : null;
    }

    public function isCurrent(RenderedDocument $rendered): bool
    {
        $this->assertEnabled();
        $this->assertTarget($rendered->target);

        if ($rendered->snapshotId === null) {
            throw new LogicException('Current revision inspection requires an ordered rendered snapshot.');
        }

        $row = $this->connection()->table('document_coalescing_targets')->useWritePdo()
            ->where('target_key', $this->key($rendered->target))->first();

        return $row !== null && ! $row->tombstoned && $row->latest_snapshot === $rendered->snapshotId
            && $row->latest_fingerprint === $rendered->fingerprint();
    }

    /** @param class-string<DocumentGenerator> $generator */
    public function capture(string $html, DocumentTarget $target, string $driver, ?DocumentMetadata $metadata = null, string $generator = DocumentGenerator::class): string
    {
        $this->assertTarget($target);
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $fingerprint = RenderedDocument::fingerprintFor($html, $target, $driver, $metadata, $generator);

        return MetadataTransaction::run($connection, function () use ($connection, $html, $target, $driver, $fingerprint, $metadata, $generator): string {
            $key = $this->key($target);
            $row = $this->lockTarget($connection, $key);

            if ($row->tombstoned) {
                throw new LogicException('Cannot register a snapshot for a deleted document target.');
            }

            $snapshot = $row->latest_fingerprint === $fingerprint && is_string($row->latest_snapshot)
                ? $row->latest_snapshot
                : (string) Str::uuid();
            $rendered = new RenderedDocument($html, $target, $driver, latestSnapshotWins: true, registeredSnapshotId: $snapshot, metadata: $metadata, generator: $generator);
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'latest_snapshot' => $snapshot,
                'latest_fingerprint' => $fingerprint,
                'pending' => $this->codec->encode($rendered),
                'worker_token' => $row->worker_token ?? (string) Str::uuid(),
                'lease_until' => $row->worker_token === null ? null : $row->lease_until,
                'queue_connection' => $row->worker_token === null ? null : $row->queue_connection,
                'queue_name' => $row->worker_token === null ? null : $row->queue_name,
            ]);

            return $snapshot;
        }, 5);
    }

    public function changed(RenderedDocument $rendered): bool
    {
        $this->assertTarget($rendered->target);
        $row = $this->connection()->table('document_coalescing_targets')->useWritePdo()
            ->where('target_key', $this->key($rendered->target))->first();

        return $row === null || $row->published_fingerprint !== $rendered->fingerprint();
    }

    public function status(DocumentTarget $target): DocumentTargetStatus
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $row = $this->connection()->table('document_coalescing_targets')->useWritePdo()
            ->where('target_key', $this->key($target))->first();

        if ($row === null) {
            return DocumentTargetStatus::NotScheduled;
        }

        if ($row->tombstoned) {
            return DocumentTargetStatus::Tombstoned;
        }

        if (is_string($row->pending)) {
            $pending = $this->codec->decode($row->pending);

            if ($pending->snapshotId === $row->latest_snapshot) {
                return DocumentTargetStatus::Pending;
            }
        }

        if (is_string($row->latest_fingerprint) && $row->latest_fingerprint === $row->published_fingerprint) {
            return DocumentTargetStatus::Published;
        }

        return DocumentTargetStatus::NotScheduled;
    }

    /** @return list<DocumentTargetEvent> */
    public function targetEvents(DocumentTarget $target, int $after = 0): array
    {
        $this->assertEnabled();
        $this->assertTarget($target);

        if ($after < 0) {
            throw new InvalidArgumentException('A target event cursor cannot be negative.');
        }

        if (config('laravel-documents.coalescing.record_target_events') !== true) {
            throw new LogicException('Document target event recording is disabled.');
        }

        return array_values($this->connection()->table('document_target_events')->useWritePdo()
            ->where('target_key', $this->key($target))->where('id', '>', $after)
            ->orderBy('id')->get()->map(fn (stdClass $row): DocumentTargetEvent => new DocumentTargetEvent(
                (int) $row->id,
                DocumentTargetEventType::from($row->type),
            ))->all());
    }

    public function targetEventCursor(DocumentTarget $target): int
    {
        $this->assertEnabled();
        $this->assertTarget($target);

        if (config('laravel-documents.coalescing.record_target_events') !== true) {
            throw new LogicException('Document target event recording is disabled.');
        }

        return (int) $this->connection()->table('document_target_events')->useWritePdo()
            ->where('target_key', $this->key($target))->max('id');
    }

    public function recordFailedAttempt(string $key, string $token): void
    {
        if (config('laravel-documents.coalescing.record_target_events') !== true) {
            return;
        }
        $connection = $this->connection();
        MetadataTransaction::run($connection, function () use ($connection, $key, $token): void {
            $row = $this->lockTarget($connection, $key);

            if ($row->worker_token === $token && $row->pending !== null && ! $row->tombstoned) {
                $this->recordTargetEvent($connection, $key, DocumentTargetEventType::AttemptFailed);
            }
        }, 5);
    }

    private function recordTargetEvent(Connection $connection, string $key, DocumentTargetEventType $type): void
    {
        $connection->table('document_target_events')->insert([
            'target_key' => $key,
            'type' => $type->value,
            'created_at' => now(),
        ]);
    }

    public function existingPublication(RenderedDocument $rendered): ?DocumentPublicationResult
    {
        $this->assertEnabled();
        $this->assertTarget($rendered->target);

        if ($rendered->snapshotId === null) {
            throw new LogicException('Synchronous reuse requires an ordered rendered snapshot.');
        }
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($rendered->target);
        $row = $connection->table('document_coalescing_targets')->useWritePdo()->where('target_key', $key)->first();

        if ($row === null || $row->latest_snapshot !== $rendered->snapshotId) {
            return DocumentPublicationResult::superseded();
        }

        if ($row->latest_fingerprint !== $rendered->fingerprint()) {
            throw new RuntimeException('The rendered document fingerprint does not match its registered snapshot.');
        }

        if ($row->published_fingerprint !== $rendered->fingerprint() || ! is_string($row->published_version)) {
            return null;
        }
        $version = $row->published_version;
        $storage = $this->storage->resolve($rendered->target->storage);

        if (! $storage->exists($rendered->target->context, $version)) {
            return null;
        }

        return MetadataTransaction::run($connection, function () use ($connection, $key, $rendered, $version): ?DocumentPublicationResult {
            $current = $this->lockTarget($connection, $key);

            if ($current->latest_snapshot !== $rendered->snapshotId) {
                return DocumentPublicationResult::superseded();
            }

            if ($current->latest_fingerprint !== $rendered->fingerprint()) {
                throw new RuntimeException('The rendered document fingerprint does not match its registered snapshot.');
            }

            if ($current->published_version !== $version || $current->published_fingerprint !== $rendered->fingerprint()) {
                return null;
            }
            $this->clearPending($connection, $key);

            return DocumentPublicationResult::published($version);
        }, 5);
    }

    public function invalidate(DocumentTarget $target): void
    {
        $this->resetTarget($target, false);
    }

    public function tombstone(DocumentTarget $target): void
    {
        $this->resetTarget($target, true);
    }

    public function retire(DocumentTarget $target): void
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $storage = $this->storage->resolve($target->storage);

        if (! $storage instanceof VersionedDocumentStorage) {
            throw new LogicException('Document retirement requires versioned document storage.');
        }

        $connection = $this->connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            throw new LogicException('Document retirement requires a Laravel-managed transaction.');
        }

        MetadataTransaction::run($connection, function () use ($target, $storage): void {
            $this->tombstone($target);
            $storage->retire($target->context);
        }, 5);
    }

    public function restore(DocumentTarget $target): void
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            throw new LogicException('Document restoration requires a Laravel-managed transaction.');
        }
        $key = $this->key($target);
        MetadataTransaction::run($connection, function () use ($connection, $key): void {
            $this->lockTarget($connection, $key);
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update(['tombstoned' => false]);
        }, 5);
    }

    private function resetTarget(DocumentTarget $target, bool $tombstone): void
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            throw new LogicException('Document invalidation requires a Laravel-managed transaction.');
        }
        $key = $this->key($target);
        MetadataTransaction::run($connection, function () use ($connection, $key, $tombstone): void {
            $row = $this->lockTarget($connection, $key);
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'tombstoned' => $tombstone || $row->tombstoned,
                'latest_snapshot' => (string) Str::uuid(),
                'latest_fingerprint' => null,
                'published_fingerprint' => null,
                'published_version' => null,
                'pending' => null,
                'worker_token' => null,
                'lease_until' => null,
                'queue_connection' => null,
                'queue_name' => null,
            ]);
        }, 5);
    }

    public function publish(GeneratedDocument $generated, DocumentStorage $storage, ?string $workerToken = null): CoalescedPublicationResult
    {
        $this->assertTarget($generated->target);

        if ($generated->snapshotId === null || ! $storage instanceof VersionedDocumentStorage) {
            throw new LogicException('Coalesced publication requires an ordered snapshot and versioned document storage.');
        }

        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($generated->target);
        $stage = $storage->stage($generated->target->context, $generated->contents, function (string $version) use ($connection, $key): void {
            $connection->table('document_coalescing_stages')->insert([
                'version' => $version,
                'target_key' => $key,
                'created_at' => now(),
            ]);
        });

        $result = MetadataTransaction::run($connection, function () use ($connection, $key, $generated, $stage, $storage, $workerToken): CoalescedPublicationResult {
            $target = $this->lockTarget($connection, $key);
            $journal = $connection->table('document_coalescing_stages')->where('version', $stage->version)->lockForUpdate()->first();

            if ($journal === null || $journal->discarding) {
                throw new RuntimeException('The document stage was reclaimed before publication.');
            }

            if ($target->latest_snapshot === $generated->snapshotId && $target->latest_fingerprint !== $generated->fingerprint) {
                throw new RuntimeException('The generated document fingerprint does not match its registered snapshot.');
            }

            if ($target->latest_snapshot !== $generated->snapshotId) {
                return CoalescedPublicationResult::superseded($target->latest_snapshot);
            }

            if ($workerToken !== null && $target->worker_token !== $workerToken) {
                return CoalescedPublicationResult::abandoned();
            }

            if ($target->published_fingerprint === $generated->fingerprint
                && is_string($target->published_version)
                && $storage->exists($generated->target->context, $target->published_version)) {
                $this->clearPending($connection, $key);

                return CoalescedPublicationResult::reused($target->published_version);
            }

            $stage->publish($storage->cleanup);
            $connection->table('document_coalescing_targets')->where('target_key', $key)
                ->update(['published_fingerprint' => $generated->fingerprint, 'published_version' => $stage->version]);
            $this->clearPending($connection, $key);
            $connection->table('document_coalescing_stages')->where('version', $stage->version)->delete();

            return CoalescedPublicationResult::published($stage->version);
        }, 5);

        if ($result->status !== CoalescedPublicationStatus::Published) {
            $stage->discard();
            $connection->table('document_coalescing_stages')->where('version', $stage->version)->delete();
        }

        if ($result->status === CoalescedPublicationStatus::Superseded && ($result->latestSnapshotId === null || $result->latestSnapshotId === '')) {
            throw new RuntimeException('The latest document snapshot marker is missing.');
        }

        return $result;
    }

    public function dispatch(RenderedDocument $rendered, ?string $queueConnection = null, ?string $queue = null): bool
    {
        if ($rendered->snapshotId === null) {
            throw new LogicException('Coalesced work requires an ordered rendered snapshot.');
        }

        $this->assertEnabled();
        $this->assertTarget($rendered->target);
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($rendered->target);
        $fingerprint = $rendered->fingerprint();
        $result = MetadataTransaction::run($connection, function () use ($connection, $key, $rendered, $fingerprint, $queueConnection, $queue): array {
            $row = $this->lockTarget($connection, $key);

            if ($row->tombstoned) {
                return ['accepted' => false, 'token' => null];
            }

            if ($row->latest_snapshot === $rendered->snapshotId && $row->latest_fingerprint !== $fingerprint) {
                throw new RuntimeException('The rendered document fingerprint does not match its registered snapshot.');
            }

            if ($row->latest_snapshot !== $rendered->snapshotId) {
                return ['accepted' => false, 'token' => null];
            }

            $token = $row->worker_token === null ? (string) Str::uuid() : $row->worker_token;
            $wake = $row->worker_token === null || $row->lease_until === null;
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'pending' => $this->codec->encode($rendered),
                'queue_connection' => $queueConnection,
                'queue_name' => $queue,
                'worker_token' => $token,
                'lease_until' => $wake ? $this->leaseUntil() : $row->lease_until,
            ]);

            return ['accepted' => true, 'token' => $wake ? $token : null];
        }, 5);

        if ($result['token'] !== null) {
            $this->wake($key, $result['token'], $queueConnection, $queue);
        }

        return $result['accepted'];
    }

    public function work(string $key, string $token): void
    {
        $this->assertEnabled();
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        while (true) {
            $payload = MetadataTransaction::run($connection, function () use ($connection, $key, $token): ?string {
                $row = $this->lockTarget($connection, $key);

                if ($row->worker_token !== $token || ! is_string($row->pending)) {
                    return null;
                }
                $connection->table('document_coalescing_targets')->where('target_key', $key)
                    ->update(['lease_until' => $this->leaseUntil()]);

                return $row->pending;
            }, 5);

            if ($payload === null) {
                return;
            }
            $rendered = $this->codec->decode($payload);
            $this->assertTarget($rendered->target);

            if ($this->key($rendered->target) !== $key) {
                throw new RuntimeException('The stored coalesced document snapshot is invalid.');
            }

            $row = $connection->table('document_coalescing_targets')->useWritePdo()->where('target_key', $key)->first();

            if ($row === null || ! is_string($row->latest_snapshot)) {
                throw new RuntimeException('The latest document snapshot marker is missing.');
            }

            if ($row->latest_snapshot === $rendered->snapshotId) {
                if ($row->latest_fingerprint !== $rendered->fingerprint()) {
                    throw new RuntimeException('The stored coalesced document fingerprint does not match its snapshot.');
                }
                $storage = $this->storage->resolve($rendered->target->storage);

                if ($row->published_fingerprint !== $rendered->fingerprint() || ! $storage->exists($rendered->target->context)) {
                    $this->publisher->publishCoalescedAttempt(DocumentGenerator::generate($rendered), $token);
                }
            }

            $finished = MetadataTransaction::run($connection, function () use ($connection, $key, $token, $payload): bool {
                $row = $this->lockTarget($connection, $key);

                if ($row->worker_token !== $token) {
                    return true;
                }

                if ($row->pending !== $payload) {
                    return false;
                }
                $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                    'pending' => null,
                    'worker_token' => null,
                    'lease_until' => null,
                ]);

                return true;
            }, 5);

            if ($finished) {
                return;
            }
        }
    }

    public function recover(): void
    {
        $this->assertEnabled();

        if ($this->connection() !== DB::connection(config('laravel-versioned-storage.connection'))) {
            throw new LogicException('Document ordering and versioned storage must use the same database connection.');
        }
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $keys = $connection->table('document_coalescing_targets')->useWritePdo()
            ->whereNotNull('pending')->where(fn (Builder $query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->pluck('target_key');
        $failure = null;
        foreach ($keys as $key) {
            try {
                /** @var ArrayObject<int, Throwable> $eventFailures */
                $eventFailures = new ArrayObject;

                try {
                    $wake = $this->reserveRecoveryWakeup($connection, $key, true, $eventFailures);
                } catch (Throwable $exception) {
                    if (! isset($eventFailures[0]) || $exception !== $eventFailures[0]) {
                        throw $exception;
                    }

                    report($exception);
                    $wake = $this->reserveRecoveryWakeup($connection, $key, false, $eventFailures);
                }

                if ($wake !== null) {
                    $this->wake($key, $wake[0], $wake[1], $wake[2]);
                }
            } catch (Throwable $exception) {
                $failure = $this->recordRecoveryFailure($failure, $exception);
            }
        }

        try {
            $this->stages->recover($connection, $this->positiveInteger('stage_retention_seconds'));
        } catch (Throwable $exception) {
            $failure = $this->recordRecoveryFailure($failure, $exception);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param  ArrayObject<int, Throwable>  $eventFailures
     * @return array{string, ?string, ?string}|null
     */
    private function reserveRecoveryWakeup(Connection $connection, string $key, bool $recordEvents, ArrayObject $eventFailures): ?array
    {
        return MetadataTransaction::run($connection, function () use ($connection, $key, $recordEvents, $eventFailures): ?array {
            $row = $this->lockTarget($connection, $key);

            if ($row->pending === null || ($row->lease_until !== null && $row->lease_until > now()->toDateTimeString())) {
                return null;
            }

            if (! is_string($row->worker_token) || $row->worker_token === '') {
                throw new RuntimeException('Pending coalesced work is missing its worker token.');
            }

            if ($recordEvents && $row->lease_until !== null && config('laravel-documents.coalescing.record_target_events') === true) {
                try {
                    $this->recordTargetEvent($connection, $key, DocumentTargetEventType::RecoveryDelayed);
                } catch (Throwable $exception) {
                    $eventFailures[0] = $exception;

                    throw $exception;
                }
            }

            $token = $row->worker_token;
            $connection->table('document_coalescing_targets')->where('target_key', $key)
                ->update(['worker_token' => $token, 'lease_until' => $this->leaseUntil()]);

            return [$token, $row->queue_connection, $row->queue_name];
        }, 5);
    }

    private function recordRecoveryFailure(?Throwable $first, Throwable $exception): Throwable
    {
        if ($first === null) {
            return $exception;
        }
        report($exception);

        return $first;
    }

    private function clearPending(Connection $connection, string $key): void
    {
        $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
            'pending' => null,
            'worker_token' => null,
            'lease_until' => null,
        ]);
    }

    private function wake(string $key, string $token, ?string $connection, ?string $queue): void
    {
        $job = new GenerateCoalescedDocument($key, $token);

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }
        Bus::dispatch($job);
    }

    private function assertTarget(DocumentTarget $target): void
    {
        if ($target->storage !== 'versioned' || ! $this->storage->resolve($target->storage) instanceof VersionedDocumentStorage) {
            throw new LogicException('Coalesced document ordering requires versioned document storage.');
        }

        if ($this->connection() !== DB::connection(config('laravel-versioned-storage.connection'))) {
            throw new LogicException('Document ordering and versioned storage must use the same database connection.');
        }
    }

    private function assertEnabled(): void
    {
        if (config('laravel-documents.coalescing.enabled') !== true) {
            throw new LogicException('Document coalescing must be enabled to process or recover coalesced work.');
        }
    }

    private function connection(): Connection
    {
        return DB::connection(config('laravel-documents.coalescing.connection'));
    }

    private function assertOutsideTransaction(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException('Coalesced document operations must start outside a database transaction.');
        }
    }

    private function lockTarget(Connection $connection, string $key): stdClass
    {
        $connection->table('document_coalescing_targets')->insertOrIgnore(['target_key' => $key]);
        $row = $connection->table('document_coalescing_targets')->where('target_key', $key)->lockForUpdate()->first();

        if ($row === null) {
            throw new RuntimeException('Failed to lock the document target.');
        }

        return $row;
    }

    private function key(DocumentTarget $target): string
    {
        return hash('sha256', $target->context->identity());
    }

    private function leaseUntil(): Carbon
    {
        return now()->addSeconds($this->positiveInteger('lease_seconds'));
    }

    private function positiveInteger(string $option): int
    {
        $value = config('laravel-documents.coalescing.'.$option);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("The coalescing option [{$option}] must be a positive integer.");
        }

        return $value;
    }
}
