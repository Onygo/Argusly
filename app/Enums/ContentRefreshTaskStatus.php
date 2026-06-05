<?php

namespace App\Enums;

enum ContentRefreshTaskStatus: string
{
    case OPEN = 'open';
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case DISMISSED = 'dismissed';
}
