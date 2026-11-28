<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

enum DocumentTargetStatus
{
    case NotScheduled;
    case Pending;
    case Published;
    case Tombstoned;
}
