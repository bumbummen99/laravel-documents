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
        public ?DocumentMetadata $metadata = null,
        public ?string $generator = null,
    ) {
        if ($driver === '') {
            throw new InvalidArgumentException('The document PDF driver configuration cannot be empty.');
        }
        $this->generatorClass();

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
            ? (config('laravel-documents.coalescing.enabled')
                ? app(DocumentCoalescer::class)->capture($this->html, $this->target, $this->driver, $this->metadata, $this->generatorClass())
                : app(DocumentSnapshotCache::class)->register($this->target, $this->fingerprint()))
            : null);
    }

    public function fingerprint(): string
    {
        return self::fingerprintFor($this->html, $this->target, $this->driver, $this->metadata ?? null, $this->generatorClass());
    }

    /** @return class-string<DocumentGenerator> */
    public function generatorClass(): string
    {
        $generator = $this->generator ?? DocumentGenerator::class;

        if (! is_a($generator, DocumentGenerator::class, true)) {
            throw new InvalidArgumentException('The document generator must extend '.DocumentGenerator::class.'.');
        }

        return $generator;
    }

    /** @param class-string<DocumentGenerator> $generator */
    public static function fingerprintFor(string $html, DocumentTarget $target, string $driver, ?DocumentMetadata $metadata = null, string $generator = DocumentGenerator::class): string
    {
        $identity = [$html, $target, $driver];

        if ($metadata !== null) {
            $identity[] = $metadata->toArray();
        }

        if ($generator !== DocumentGenerator::class) {
            $identity[] = ['generator' => $generator];
        }

        return hash('sha256', serialize($identity));
    }
}
