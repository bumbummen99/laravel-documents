<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Pdf;

use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * @internal
 */
final class DocumentPdfBuilder extends PdfBuilder
{
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
