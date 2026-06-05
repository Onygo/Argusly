<?php

namespace App\Enums;

enum DistributionPlanStatus: string
{
    case DRAFT = 'draft';
    case READY = 'ready';
    case SCHEDULED = 'scheduled';
    case QUEUED = 'queued';
    case DISTRIBUTED = 'distributed';
    case FAILED = 'failed';
    case CANCELED = 'canceled';
}
