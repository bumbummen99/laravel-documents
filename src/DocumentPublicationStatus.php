<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

enum DocumentPublicationStatus
{
    case Published;
    case Superseded;
}
