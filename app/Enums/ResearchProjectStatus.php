<?php

namespace App\Enums;

enum ResearchProjectStatus: string
{
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case FETCHING = 'fetching';
    case EXTRACTING = 'extracting';
    case SUMMARIZING = 'summarizing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
