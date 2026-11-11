<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Console\Commands;

use Illuminate\Console\Command;

class LaravelDocumentsCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-documents:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-documents.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('LaravelDocuments placeholder command executed.');

        return self::SUCCESS;
    }
}
