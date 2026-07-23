<?php

namespace App\Enums;

enum JobPostStatus: string
{
    case New = 'new';
    case Enriching = 'enriching';
    case Enriched = 'enriched';
    case NoContact = 'no_contact';
}
