<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

/** @internal */
final readonly class CoalescedPublicationResult
{
    private function __construct(
        public CoalescedPublicationStatus $status,
        public ?string $version = null,
        public ?string $latestSnapshotId = null,
    ) {}

    public static function published(string $version): self
    {
        return new self(CoalescedPublicationStatus::Published, $version);
    }

    public static function reused(string $version): self
    {
        return new self(CoalescedPublicationStatus::Reused, $version);
    }

    public static function superseded(?string $latestSnapshotId): self
    {
        return new self(CoalescedPublicationStatus::Superseded, latestSnapshotId: $latestSnapshotId);
    }

    public static function abandoned(): self
    {
        return new self(CoalescedPublicationStatus::Abandoned);
    }
}
