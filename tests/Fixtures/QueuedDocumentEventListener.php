<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedDocumentEventListener implements ShouldQueue
{
    public function handle(object $event): void {}
}
