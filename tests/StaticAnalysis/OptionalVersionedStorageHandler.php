<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\StaticAnalysis;

use Closure;
use LogicException;
use SkyRaptor\LaravelVersionedStorage\StagedVersion;
use SkyRaptor\LaravelVersionedStorage\VersionedStorage;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

/**
 * Signatures used from the optional versioned-storage package when it is absent.
 */
abstract class OptionalVersionedStorageHandler
{
    abstract public function write(Closure $callback, bool $cleanup = false): string;

    abstract public function stage(Closure $callback, bool $publish = false, bool $cleanup = false): StagedVersion;

    abstract public function discardStage(string $version): void;

    abstract public function get(string $path, ?string $version = null): string;

    abstract public function exists(string $path, ?string $version = null): bool;

    /**
     * @return list<string>
     */
    abstract public function versions(): array;
}

abstract class OptionalStagedVersion
{
    public readonly string $version;

    abstract public function publish(bool $cleanup = false): string;

    abstract public function discard(): void;
}

class OptionalVersionedStorage
{
    public static function directory(string $path, ?Closure $callback = null, bool $cleanup = false, ?string $disk = null): string|VersionedStorageHandler
    {
        throw new LogicException('The optional versioned-storage package is not installed.');
    }
}

if (! class_exists(VersionedStorageHandler::class)) {
    class_alias(OptionalVersionedStorageHandler::class, VersionedStorageHandler::class);
}

if (! class_exists(StagedVersion::class)) {
    class_alias(OptionalStagedVersion::class, StagedVersion::class);
}

if (! class_exists(VersionedStorage::class)) {
    class_alias(OptionalVersionedStorage::class, VersionedStorage::class);
}
