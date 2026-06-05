<?php

namespace App\Enums;

enum ResearchSourceFetchStatus: string
{
    case PENDING = 'pending';
    case FETCHING = 'fetching';
    case FETCHED = 'fetched';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
