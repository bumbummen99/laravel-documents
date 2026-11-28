<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An immutable revision of a document: its rendered
 * HTML, storage context, and configuration identifiers. No document, driver,
 * or storage implementation is serialized into the rendered result.
 */
final readonly class RenderedDocument
{
    /**
     * Publication ordering identity, or null when the policy is disabled.
     */
    public ?string $snapshotId;

    public function __construct(
        /**
         * The rendered HTML of the document.
         */
        public string $html,
        /**
         * Where and how the PDF file is stored.
         */
        public DocumentTarget $target,
        /**
         * The registered PDF driver configuration.
         */
        public string $driver = 'default',
        ?bool $latestSnapshotWins = null,
        ?string $registeredSnapshotId = null,
    ) {
        if ($driver === '') {
            throw new InvalidArgumentException('The document PDF driver configuration cannot be empty.');
        }

        $latestSnapshotWins ??= config('laravel-documents.latest_snapshot_wins', false);

        if (! is_bool($latestSnapshotWins)) {
            throw new InvalidArgumentException('The latest snapshot wins option must be a boolean.');
        }

        if (config('laravel-documents.coalescing.enabled') && ! $latestSnapshotWins) {
            throw new InvalidArgumentException('Coalesced document generation requires latest snapshot ordering for every document.');
        }

        if ($registeredSnapshotId !== null && (! $latestSnapshotWins || ! Str::isUuid($registeredSnapshotId)
            || config('laravel-documents.coalescing.enabled') !== true)) {
            throw new InvalidArgumentException('Restoring a registered snapshot requires coalescing and a valid UUID.');
        }

        $this->snapshotId = $registeredSnapshotId ?? ($latestSnapshotWins
            ? app(DocumentSnapshotCache::class)->register($this->target, $this->fingerprint())
            : null);
    }

    public function fingerprint(): string
    {
        return self::fingerprintFor($this->html, $this->target, $this->driver);
    }

    public static function fingerprintFor(string $html, DocumentTarget $target, string $driver): string
    {
        return hash('sha256', serialize([$html, $target, $driver]));
    }
}
