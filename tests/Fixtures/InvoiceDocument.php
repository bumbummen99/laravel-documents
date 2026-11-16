<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use SkyRaptor\LaravelDocuments\Documents\Document;

class InvoiceDocument extends Document
{
    /** @var view-string|null */
    public static ?string $view = 'invoice';

    public static ?string $disk = 'documents';

    public function __construct(public int $number)
    {
        //
    }

    public function path(): string
    {
        return "invoices/{$this->number}.pdf";
    }

    public function data(): array
    {
        return ['number' => $this->number];
    }
}
