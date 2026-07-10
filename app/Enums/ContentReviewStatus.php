<?php

namespace App\Enums;

enum ContentReviewStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case REVIEWED = 'reviewed';
    case CAMPAIGN_READY = 'campaign_ready';
    case EXCLUDED = 'excluded';
    case ACTIVATED = 'activated';
    case MEASURED = 'measured';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
