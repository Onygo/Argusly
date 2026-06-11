<?php

namespace App\Enums;

enum SignalStatus: string
{
    case NEW = 'new';
    case PROCESSING = 'processing';
    case DETECTED = 'detected';
    case REVIEWING = 'reviewing';
    case PUBLISHED = 'published';
    case DISMISSED = 'dismissed';
    case RESOLVED = 'resolved';
    case ARCHIVED = 'archived';

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::PUBLISHED, self::DISMISSED, self::RESOLVED, self::ARCHIVED], true);
    }
}
