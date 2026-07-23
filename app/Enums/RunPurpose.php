<?php

namespace App\Enums;

enum RunPurpose: string
{
    case Scrape = 'scrape';
    case Enrich = 'enrich';
    case External = 'external';
}
