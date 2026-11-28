<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

enum DocumentTargetEventType: string
{
    case AttemptFailed = 'attempt_failed';
    case RecoveryDelayed = 'recovery_delayed';
}
