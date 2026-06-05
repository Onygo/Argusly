<?php

namespace App\Enums;

enum ContentIntelligenceStatus: string
{
    case HEALTHY = 'healthy';
    case OPPORTUNITY = 'opportunity';
    case AT_RISK = 'at_risk';
    case DECAYING = 'decaying';
    case AI_OPTIMIZED = 'ai_optimized';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => 'Healthy',
            self::OPPORTUNITY => 'Opportunity',
            self::AT_RISK => 'At Risk',
            self::DECAYING => 'Decaying',
            self::AI_OPTIMIZED => 'AI Optimized',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::HEALTHY => 'green',
            self::OPPORTUNITY => 'sky',
            self::AT_RISK => 'amber',
            self::DECAYING => 'red',
            self::AI_OPTIMIZED => 'emerald',
        };
    }
}
