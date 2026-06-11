<?php

namespace App\Enums;

enum OpportunityStatus: string
{
    case OPEN = 'open';
    case REVIEWING = 'reviewing';
    case APPROVED = 'approved';
    case PLANNED = 'planned';
    case ACTIONED = 'actioned';
    case RESOLVED = 'resolved';
    case DISMISSED = 'dismissed';
    case ARCHIVED = 'archived';

    public function isOpen(): bool
    {
        return in_array($this, [self::OPEN, self::REVIEWING, self::APPROVED, self::PLANNED], true);
    }

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
