<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

trait HasLatestSnapshotWins
{
    /**
     * Null inherits the package default; false explicitly opts out.
     */
    public static ?bool $latestSnapshotWins = null;

    public function latestSnapshotWins(): ?bool
    {
        return static::$latestSnapshotWins;
    }
}
