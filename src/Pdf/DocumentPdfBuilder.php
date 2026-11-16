<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Pdf;

use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\LaravelPdf\PdfOptions;

/**
 * @internal
 */
final class DocumentPdfBuilder extends PdfBuilder
{
    public function __construct(private readonly string $configurationName) {}

    protected function getDriver(): PdfDriver
    {
        $originalDriver = $this->driver;
        $driver = $originalDriver ?? ($this->driverName
            ? app("laravel-pdf.driver.{$this->driverName}")
            : app(PdfDriver::class));
        $this->driver = $driver instanceof BrowsershotDriver ? clone $driver : $driver;

        try {
            return parent::getDriver();
        } finally {
            $this->driver = $originalDriver;
        }
    }

    protected function cacheFingerprint(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        return serialize([
            $this->configurationName,
            parent::cacheFingerprint($html, $headerHtml, $footerHtml, $options),
        ]);
    }

    protected function configureBrowsershotDriver(PdfDriver $driver): void
    {
        if (! $driver instanceof BrowsershotDriver) {
            return;
        }

        // Spatie's defaults otherwise clear customizations from the driver factory.
        if ($this->onLambda) {
            $driver->onLambda();
        }

        if ($this->customizeBrowsershot !== null) {
            $driver->customizeBrowsershot($this->customizeBrowsershot);
        }
    }
}
