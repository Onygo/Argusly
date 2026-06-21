<?php

namespace App\Enums;

enum HumanSignalType: string
{
    case VISIBILITY_TREND = 'visibility_trend';
    case COMPETITOR_SHIFT = 'competitor_shift';
    case CONTENT_GAP = 'content_gap';
    case FAQ_GAP = 'faq_gap';
    case CITATION_PATTERN = 'citation_pattern';
    case CAMPAIGN_PATTERN = 'campaign_pattern';
    case AUTHORITY_GROWTH = 'authority_growth';
    case AUTHORITY_DECLINE = 'authority_decline';
    case TOPIC_OPPORTUNITY = 'topic_opportunity';
    case CONTENT_PERFORMANCE = 'content_performance';
    case CONVERSION_PATTERN = 'conversion_pattern';
    case EMERGING_TOPIC = 'emerging_topic';
    case CUSTOM = 'custom';

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
