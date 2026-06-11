<?php

namespace App\Enums;

enum SignalSourceType: string
{
    case RSS_FEED = 'rss_feed';
    case WEBSITE_FEED = 'website_feed';
    case LLM_TRACKING = 'llm_tracking';
    case ANALYTICS = 'analytics';
    case COMPETITOR = 'competitor';
    case MANUAL = 'manual';
    case API = 'api';
    case LINKEDIN = 'linkedin';
    case WEBHOOK = 'webhook';
    case SEARCH_TREND = 'search_trend';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
