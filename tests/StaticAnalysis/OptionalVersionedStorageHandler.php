<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\StaticAnalysis;

use Closure;
use SkyRaptor\LaravelVersionedStorage\VersionedStorageHandler;

/**
 * Signatures used from the optional versioned-storage package when it is absent.
 */
abstract class OptionalVersionedStorageHandler
{
    abstract public function write(Closure $callback, bool $cleanup = false): string;

    abstract public function get(string $path, ?string $version = null): string;

    abstract public function exists(string $path, ?string $version = null): bool;

    /**
     * @return list<string>
     */
    abstract public function versions(): array;
}

if (! class_exists(VersionedStorageHandler::class)) {
    class_alias(OptionalVersionedStorageHandler::class, VersionedStorageHandler::class);
}
