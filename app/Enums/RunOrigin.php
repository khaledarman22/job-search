<?php

namespace App\Enums;

enum RunOrigin: string
{
    case System = 'system';
    case Synced = 'synced';
}
