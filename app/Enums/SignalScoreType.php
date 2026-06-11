<?php

namespace App\Enums;

enum SignalScoreType: string
{
    case BRAND_VISIBILITY = 'brand_visibility';
    case COMPETITOR_PRESSURE = 'competitor_pressure';
    case TREND_VELOCITY = 'trend_velocity';
    case RISK_LEVEL = 'risk_level';
    case OPPORTUNITY_READINESS = 'opportunity_readiness';
    case SENTIMENT_HEALTH = 'sentiment_health';
    case SOURCE_QUALITY = 'source_quality';

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
