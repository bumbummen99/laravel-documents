<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

final readonly class DocumentPublicationResult
{
    private function __construct(
        public DocumentPublicationStatus $status,
        public ?string $version,
    ) {}

    public static function published(?string $version): self
    {
        return new self(DocumentPublicationStatus::Published, $version);
    }

    public static function superseded(): self
    {
        return new self(DocumentPublicationStatus::Superseded, null);
    }
}
