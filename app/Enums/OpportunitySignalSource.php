<?php

namespace App\Enums;

enum OpportunitySignalSource: string
{
    case SEARCH_TRENDS = 'search_trends';
    case AI_CITATION_TRACKING = 'ai_citation_tracking';
    case INTERNAL_ANALYTICS = 'internal_analytics';
    case CONTENT_DECAY = 'content_decay';
    case ENGAGEMENT_ANALYTICS = 'engagement_analytics';
    case COMPETITOR_INTELLIGENCE = 'competitor_intelligence';
    case SIGNAL_INTELLIGENCE = 'signal_intelligence';
    case CONTENT_CLUSTER = 'content_cluster';
    case MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
