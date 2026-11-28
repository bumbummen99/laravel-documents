<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

/** @internal */
enum CoalescedPublicationStatus
{
    case Published;
    case Reused;
    case Superseded;
    case Abandoned;
}
