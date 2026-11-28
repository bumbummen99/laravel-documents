<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An immutable revision of a document: its rendered
 * HTML, storage context, and configuration identifiers. No document, driver,
 * or storage implementation is serialized into the rendered result.
 * Constructing an ordered snapshot registers it immediately; in coalescing
 * mode this also durably records recoverable work before construction returns.
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

        if ($registeredSnapshotId !== null) {
            if (config('laravel-documents.coalescing.enabled') === true) {
                $validId = Str::isUuid($registeredSnapshotId);
            } else {
                [$id, $fingerprint] = array_pad(explode(':', $registeredSnapshotId, 2), 2, null);
                $validId = Str::isUuid($id) && is_string($fingerprint) && hash_equals($this->fingerprint(), $fingerprint);
            }

            if (! $latestSnapshotWins || ! $validId) {
                throw new InvalidArgumentException('Restoring a registered snapshot requires a valid ordered snapshot ID.');
            }
        }

        $this->snapshotId = $registeredSnapshotId ?? $this->registerSnapshot($latestSnapshotWins);
    }

    private function registerSnapshot(bool $latestSnapshotWins): ?string
    {
        if (! $latestSnapshotWins) {
            return null;
        }

        if (config('laravel-documents.coalescing.enabled')) {
            return app(DocumentCoalescer::class)->capture($this->html, $this->target, $this->driver, $this->metadata, $this->generatorClass());
        }

        return app(DocumentSnapshotCache::class)->register($this->target, $this->fingerprint());
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
