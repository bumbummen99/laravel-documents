<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use LogicException;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfOptions;

final class ConfiguredPdfDriver implements PdfDriver
{
    public function __construct(private readonly string $prefix) {}

    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        return $this->prefix.':'.$options->format.':'.$html;
    }

    public function savePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options, string $path): void
    {
        throw new LogicException('The document package generates PDF contents instead of asking the driver to save.');
    }
}
