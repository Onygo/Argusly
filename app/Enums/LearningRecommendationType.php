<?php

namespace App\Enums;

enum LearningRecommendationType: string
{
    case REPOST = 'repost';
    case REFRESH = 'refresh';
    case CAMPAIGN_EXPANSION = 'campaign_expansion';
    case SUPPORTING_CONTENT = 'supporting_content';
    case CTA_OPTIMIZATION = 'cta_optimization';
    case HOOK_OPTIMIZATION = 'hook_optimization';
    case TONE_OPTIMIZATION = 'tone_optimization';
    case AI_VISIBILITY = 'ai_visibility';

    public function label(): string
    {
        return match ($this) {
            self::REPOST => 'Repost suggestion',
            self::REFRESH => 'Refresh suggestion',
            self::CAMPAIGN_EXPANSION => 'Campaign expansion',
            self::SUPPORTING_CONTENT => 'Supporting content',
            self::CTA_OPTIMIZATION => 'CTA optimization',
            self::HOOK_OPTIMIZATION => 'Hook optimization',
            self::TONE_OPTIMIZATION => 'Tone optimization',
            self::AI_VISIBILITY => 'AI visibility',
        };
    }
}
