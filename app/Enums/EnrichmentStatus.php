<?php

namespace App\Enums;

enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Enriched = 'enriched';
    case NoContact = 'no_contact';
    case NoWebsite = 'no_website';
    case Failed = 'failed';
}
