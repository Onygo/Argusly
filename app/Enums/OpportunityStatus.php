<?php

namespace App\Enums;

enum OpportunityStatus: string
{
    case OPEN = 'open';
    case REVIEWING = 'reviewing';
    case PLANNED = 'planned';
    case ACTIONED = 'actioned';
    case DISMISSED = 'dismissed';
    case ARCHIVED = 'archived';

    public function isOpen(): bool
    {
        return in_array($this, [self::OPEN, self::REVIEWING, self::PLANNED], true);
    }
}
