<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Jobs\GenerateCoalescedDocument;
use SkyRaptor\LaravelDocuments\Storage\Contexts\FilesystemDocumentStorageContext;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;
use SkyRaptor\LaravelDocuments\Storage\DocumentStorageManager;
use SkyRaptor\LaravelDocuments\Storage\VersionedDocumentStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use stdClass;

/**
 * Coordinates ordered publication and recoverable, at-least-once queue wakeups.
 */
final class DocumentCoalescer
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly DocumentPublisher $publisher,
        private readonly DocumentStorageManager $storage,
    ) {}

    public function register(DocumentTarget $target, ?string $fingerprint): string
    {
        $this->assertTarget($target);
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);

        return $connection->transaction(function () use ($connection, $target, $fingerprint): string {
            $key = $this->key($target);
            $row = $this->lockTarget($connection, $key);

            if ($row->tombstoned) {
                throw new LogicException('Cannot register a snapshot for a deleted document target.');
            }

            if ($fingerprint !== null && $row->latest_fingerprint === $fingerprint && is_string($row->latest_snapshot)) {
                return $row->latest_snapshot;
            }

            $snapshot = (string) Str::uuid();
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'latest_snapshot' => $snapshot,
                'latest_fingerprint' => $fingerprint,
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
            $pending = $this->decodeSnapshot($row->pending);

            if ($pending->snapshotId === $row->latest_snapshot) {
                return DocumentTargetStatus::Pending;
            }
        }

        if (is_string($row->latest_fingerprint) && $row->latest_fingerprint === $row->published_fingerprint) {
            return DocumentTargetStatus::Published;
        }

        return DocumentTargetStatus::NotScheduled;
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

        return $connection->transaction(function () use ($connection, $key, $rendered, $version): ?DocumentPublicationResult {
            $current = $this->lockTarget($connection, $key);

            if ($current->latest_snapshot !== $rendered->snapshotId) {
                return DocumentPublicationResult::superseded();
            }

            if ($current->latest_fingerprint !== $rendered->fingerprint()) {
                throw new RuntimeException('The rendered document fingerprint does not match its registered snapshot.');
            }

            return $current->published_version === $version && $current->published_fingerprint === $rendered->fingerprint()
                ? DocumentPublicationResult::published($version)
                : null;
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

    public function restore(DocumentTarget $target): void
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            throw new LogicException('Document restoration requires a Laravel-managed transaction.');
        }
        $key = $this->key($target);
        $connection->transaction(function () use ($connection, $key): void {
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
        $connection->transaction(function () use ($connection, $key, $tombstone): void {
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

    /** @param Closure(string): void $superseded */
    public function publish(GeneratedDocument $generated, DocumentStorage $storage, Closure $superseded): string|false
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

        $latest = null;
        $published = $connection->transaction(function () use ($connection, $key, $generated, $stage, $storage, &$latest): bool {
            $target = $this->lockTarget($connection, $key);
            $journal = $connection->table('document_coalescing_stages')->where('version', $stage->version)->lockForUpdate()->first();

            if ($journal === null || $journal->discarding) {
                throw new RuntimeException('The document stage was reclaimed before publication.');
            }

            if ($target->latest_snapshot === $generated->snapshotId && $target->latest_fingerprint !== $generated->fingerprint) {
                throw new RuntimeException('The generated document fingerprint does not match its registered snapshot.');
            }

            if ($target->latest_snapshot !== $generated->snapshotId) {
                $latest = $target->latest_snapshot;

                return false;
            }

            $stage->publish($storage->cleanup);
            $connection->table('document_coalescing_targets')->where('target_key', $key)
                ->update(['published_fingerprint' => $generated->fingerprint, 'published_version' => $stage->version]);
            $connection->table('document_coalescing_stages')->where('version', $stage->version)->delete();

            return true;
        }, 5);

        if (! $published) {
            $stage->discard();
            $connection->table('document_coalescing_stages')->where('version', $stage->version)->delete();

            if (! is_string($latest) || $latest === '') {
                throw new RuntimeException('The latest document snapshot marker is missing.');
            }
            $superseded($latest);

            return false;
        }

        return $stage->version;
    }

    public function dispatch(RenderedDocument $rendered, ?string $queueConnection = null, ?string $queue = null): bool
    {
        if ($rendered->snapshotId === null) {
            throw new LogicException('Coalesced work requires an ordered rendered snapshot.');
        }

        return $this->dispatchSnapshot($rendered->html, $rendered->target, $rendered->driver, $rendered->snapshotId, $queueConnection, $queue);
    }

    public function dispatchDocument(Document $document, ?string $queueConnection = null, ?string $queue = null): bool
    {
        $this->assertEnabled();
        $enabled = $document->latestSnapshotWins() ?? config('laravel-documents.latest_snapshot_wins', false);

        if ($enabled !== true) {
            throw new LogicException('Coalesced work requires latest snapshot ordering to be enabled.');
        }

        return $this->dispatchSnapshot($document->html(), $document->target(), $document->pdfDriver(), null, $queueConnection, $queue);
    }

    private function dispatchSnapshot(string $html, DocumentTarget $target, string $driver, ?string $snapshotId, ?string $queueConnection, ?string $queue): bool
    {
        $this->assertEnabled();
        $this->assertTarget($target);
        $connection = $this->connection();
        $this->assertOutsideTransaction($connection);
        $key = $this->key($target);
        $fingerprint = RenderedDocument::fingerprintFor($html, $target, $driver);
        $result = $connection->transaction(function () use ($connection, $key, $html, $target, $driver, $snapshotId, $fingerprint, $queueConnection, $queue): array {
            $row = $this->lockTarget($connection, $key);

            if ($row->tombstoned) {
                if ($snapshotId !== null) {
                    return ['accepted' => false, 'token' => null];
                }

                throw new LogicException('Cannot register a snapshot for a deleted document target.');
            }

            if ($snapshotId !== null && $row->latest_snapshot === $snapshotId && $row->latest_fingerprint !== $fingerprint) {
                throw new RuntimeException('The rendered document fingerprint does not match its registered snapshot.');
            }

            if ($snapshotId !== null && $row->latest_snapshot !== $snapshotId) {
                return ['accepted' => false, 'token' => null];
            }

            $snapshotId ??= $row->latest_fingerprint === $fingerprint && is_string($row->latest_snapshot)
                ? $row->latest_snapshot
                : (string) Str::uuid();
            $rendered = new RenderedDocument($html, $target, $driver, latestSnapshotWins: true, registeredSnapshotId: $snapshotId);
            $token = $row->worker_token === null ? (string) Str::uuid() : null;
            $connection->table('document_coalescing_targets')->where('target_key', $key)->update([
                'latest_snapshot' => $snapshotId,
                'latest_fingerprint' => $fingerprint,
                'pending' => $this->encodeSnapshot($rendered),
                'queue_connection' => $queueConnection,
                'queue_name' => $queue,
                'worker_token' => $token ?? $row->worker_token,
                'lease_until' => $token === null ? $row->lease_until : $this->leaseUntil(),
            ]);

            return ['accepted' => true, 'token' => $token];
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
            $payload = $connection->transaction(function () use ($connection, $key, $token): ?string {
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
            $rendered = $this->decodeSnapshot($payload);
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
                    $this->publisher->publishWithResult($this->generator->generate($rendered));
                }
            }

            $finished = $connection->transaction(function () use ($connection, $key, $token, $payload): bool {
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
            ->whereNotNull('pending')->where('lease_until', '<=', now())->pluck('target_key');
        foreach ($keys as $key) {
            $wake = $connection->transaction(function () use ($connection, $key): ?array {
                $row = $this->lockTarget($connection, $key);

                if ($row->pending === null || $row->lease_until === null || $row->lease_until > now()->toDateTimeString()) {
                    return null;
                }

                if (! is_string($row->worker_token) || $row->worker_token === '') {
                    throw new RuntimeException('Pending coalesced work is missing its worker token.');
                }
                $token = $row->worker_token;
                $connection->table('document_coalescing_targets')->where('target_key', $key)
                    ->update(['worker_token' => $token, 'lease_until' => $this->leaseUntil()]);

                return [$token, $row->queue_connection, $row->queue_name];
            }, 5);

            if ($wake !== null) {
                $this->wake($key, $wake[0], $wake[1], $wake[2]);
            }
        }
        $this->recoverStages($connection);
    }

    private function recoverStages(Connection $connection): void
    {
        $retention = $this->positiveInteger('stage_retention_seconds');
        $versions = $connection->table('document_coalescing_stages')->useWritePdo()
            ->where('created_at', '<=', now()->subSeconds($retention))->pluck('version');
        foreach ($versions as $version) {
            $claimed = $connection->transaction(function () use ($connection, $version): bool {
                $journal = $connection->table('document_coalescing_stages')->where('version', $version)
                    ->lockForUpdate()->first();

                if ($journal === null) {
                    return false;
                }
                $connection->table('document_coalescing_stages')->where('version', $version)->update(['discarding' => true]);

                return true;
            }, 5);

            if (! $claimed) {
                continue;
            }
            $stage = $connection->table('versioned_storage_stages as stages')->useWritePdo()
                ->join('versioned_storage_resources as resources', 'resources.id', '=', 'stages.resource_id')
                ->where('stages.version', $version)
                ->first(['stages.state', 'stages.cleanup_after', 'resources.path', 'resources.disk', 'resources.is_directory']);

            if ($stage === null) {
                $connection->table('document_coalescing_stages')->where('version', $version)->delete();

                continue;
            }

            if ($stage->state === 'uploading' && $stage->cleanup_after > now()->toDateTimeString()) {
                continue;
            }

            if (! $stage->is_directory) {
                throw new RuntimeException('The recorded document stage is not a directory.');
            }
            $handler = VersionedStorage::directory($stage->path, disk: $stage->disk);

            if (! $handler instanceof VersionedStorageHandler) {
                throw new LogicException('Expected a versioned storage handler.');
            }
            $handler->discardStage($version);
            $connection->table('document_coalescing_stages')->where('version', $version)->delete();
        }
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

    private function encodeSnapshot(RenderedDocument $rendered): string
    {
        $context = FilesystemDocumentStorageContext::from($rendered->target->context);

        return json_encode([
            'html' => $rendered->html,
            'storage' => $rendered->target->storage,
            'path' => $context->path,
            'disk' => $context->disk,
            'driver' => $rendered->driver,
            'snapshot' => $rendered->snapshotId,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeSnapshot(string $payload): RenderedDocument
    {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! isset($data['html'], $data['storage'], $data['path'], $data['disk'], $data['driver'], $data['snapshot'])
            || ! is_string($data['html']) || ! is_string($data['storage']) || ! is_string($data['path'])
            || ! is_string($data['disk']) || ! is_string($data['driver']) || ! is_string($data['snapshot'])) {
            throw new RuntimeException('The stored coalesced document snapshot is invalid.');
        }

        return new RenderedDocument(
            $data['html'],
            new DocumentTarget($data['storage'], new FilesystemDocumentStorageContext($data['path'], $data['disk'])),
            $data['driver'],
            latestSnapshotWins: true,
            registeredSnapshotId: $data['snapshot'],
        );
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
