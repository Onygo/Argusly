<?php

namespace App\Enums;

enum OpportunityCategory: string
{
    case CONTENT_GAP = 'content_gap';
    case TREND_OPPORTUNITY = 'trend_opportunity';
    case REFRESH_OPPORTUNITY = 'refresh_opportunity';
    case COMPETITOR_MOVEMENT = 'competitor_movement';
    case AI_VISIBILITY_OPPORTUNITY = 'ai_visibility_opportunity';
    case ENGAGEMENT_OPPORTUNITY = 'engagement_opportunity';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
