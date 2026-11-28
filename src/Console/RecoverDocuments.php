<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Console;

use Illuminate\Console\Command;
use SkyRaptor\LaravelDocuments\DocumentCoalescer;

final class RecoverDocuments extends Command
{
    protected $signature = 'documents:recover';

    protected $description = 'Recover pending coalesced document work and abandoned stages';

    public function handle(DocumentCoalescer $coalescer): int
    {
        $coalescer->recover();

        return self::SUCCESS;
    }
}
