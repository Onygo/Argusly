<?php

namespace App\Enums;

enum EmailMarketingExportStatus: string
{
    case PENDING = 'pending';
    case EXPORTED = 'exported';
    case FAILED = 'failed';
    case SYNCED = 'synced';
}
