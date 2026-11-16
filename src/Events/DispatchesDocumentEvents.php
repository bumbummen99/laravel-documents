<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Events;

use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * @internal
 */
trait DispatchesDocumentEvents
{
    private function dispatchDocumentEvent(object $event): void
    {
        try {
            Event::dispatch($event);
        } catch (Throwable $exception) {
            // Listener failures must not alter generation or publication outcomes.
            report($exception);
        }
    }
}
