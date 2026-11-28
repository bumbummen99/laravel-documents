<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Database\Connection;
use LogicException;
use RuntimeException;
use SkyRaptor\LaravelVersionedStorage\Database\MetadataTransaction;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;
use Throwable;

/** @internal */
final class CoalescedStageRecovery
{
    public function recover(Connection $connection, int $retention): void
    {
        $versions = $connection->table('document_coalescing_stages')->useWritePdo()
            ->where('created_at', '<=', now()->subSeconds($retention))->pluck('version');
        $failure = null;
        foreach ($versions as $version) {
            try {
                $this->recoverStage($connection, $version);
            } catch (Throwable $exception) {
                if ($failure === null) {
                    $failure = $exception;
                } else {
                    report($exception);
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function recoverStage(Connection $connection, string $version): void
    {
        $claimed = MetadataTransaction::run($connection, function () use ($connection, $version): bool {
            $journal = $connection->table('document_coalescing_stages')->where('version', $version)
                ->lockForUpdate()->first();

            if ($journal === null) {
                return false;
            }
            $connection->table('document_coalescing_stages')->where('version', $version)->update(['discarding' => true]);

            return true;
        }, 5);

        if (! $claimed) {
            return;
        }
        $stage = $connection->table('versioned_storage_stages as stages')->useWritePdo()
            ->join('versioned_storage_resources as resources', 'resources.id', '=', 'stages.resource_id')
            ->where('stages.version', $version)
            ->first(['stages.state', 'stages.cleanup_after', 'resources.path', 'resources.disk', 'resources.is_directory']);

        if ($stage === null) {
            $connection->table('document_coalescing_stages')->where('version', $version)->delete();

            return;
        }

        if ($stage->state === 'uploading' && $stage->cleanup_after > now()->toDateTimeString()) {
            return;
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
