<?php

namespace App\Enums;

enum ContactStatus: string
{
    case New = 'new';
    case Queued = 'queued';
    case Contacted = 'contacted';
    case Suppressed = 'suppressed';
    case Invalid = 'invalid';
}
