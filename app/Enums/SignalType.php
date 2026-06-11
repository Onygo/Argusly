<?php

namespace App\Enums;

enum SignalType: string
{
    case BRAND_MENTIONED = 'brand_mentioned';
    case BRAND_MISSING = 'brand_missing';
    case COMPETITOR_MENTIONED = 'competitor_mentioned';
    case COMPETITOR_DOMINANCE = 'competitor_dominance';
    case TOPIC_TRENDING = 'topic_trending';
    case FEED_ITEM_PUBLISHED = 'feed_item_published';
    case NEGATIVE_SENTIMENT = 'negative_sentiment';
    case OWNED_CITATION_MISSING = 'owned_citation_missing';
    case COMPETITOR_CONTENT_SPIKE = 'competitor_content_spike';
    case CONTENT_GAP_SIGNAL = 'content_gap_signal';
    case RISK_REPUTATION = 'risk_reputation';
    case RISK_COMPETITOR_PRESSURE = 'risk_competitor_pressure';
    case RISK_DECLINING_VISIBILITY = 'risk_declining_visibility';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
