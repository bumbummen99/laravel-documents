<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Jobs;

/**
 * The same target may be queued any number of times. Every job publishes
 * a new version, and the most recently published version becomes current.
 * Publication order is not necessarily dispatch or job-completion order.
 */
class GenerateVersionedDocument extends AbstractGenerateDocument
{
    //
}
