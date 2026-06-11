<?php

namespace App\Enums;

enum SignalCategory: string
{
    case MENTION = 'mention';
    case BRAND_VISIBILITY = 'brand_visibility';
    case COMPETITOR_VISIBILITY = 'competitor_visibility';
    case TREND = 'trend';
    case OPPORTUNITY = 'opportunity';
    case RISK = 'risk';
    case FEED = 'feed';
    case ENGAGEMENT = 'engagement';
    case AI_VISIBILITY = 'ai_visibility';

    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
