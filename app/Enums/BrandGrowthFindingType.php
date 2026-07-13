<?php

namespace App\Enums;

enum BrandGrowthFindingType: string
{
    case AUDIENCE_OPPORTUNITY = 'audience_opportunity';
    case PERSONA_GAP = 'persona_gap';
    case INDUSTRY_GAP = 'industry_gap';
    case POSITIONING_GAP = 'positioning_gap';
    case MESSAGING_GAP = 'messaging_gap';
    case AUTHORITY_GAP = 'authority_gap';
    case EVIDENCE_GAP = 'evidence_gap';
    case CONTENT_GAP = 'content_gap';
    case COMPETITOR_THREAT = 'competitor_threat';
    case COMPETITOR_OPPORTUNITY = 'competitor_opportunity';
    case AI_VISIBILITY_GAP = 'ai_visibility_gap';
    case SERP_OPPORTUNITY = 'serp_opportunity';
    case CAMPAIGN_OPPORTUNITY = 'campaign_opportunity';
    case CHANNEL_OPPORTUNITY = 'channel_opportunity';
    case MEASUREMENT_GAP = 'measurement_gap';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
