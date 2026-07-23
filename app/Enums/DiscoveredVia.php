<?php

namespace App\Enums;

enum DiscoveredVia: string
{
    case JobPosting = 'job_posting';
    case Enrichment = 'enrichment';
    case Manual = 'manual';
}
