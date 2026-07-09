<?php

namespace App\Support\Intelligence;

enum IntelligenceSignalType: string
{
    case TRAFFIC_TREND = 'traffic_trend';
    case VISIBILITY_TREND = 'visibility_trend';
    case ENGAGEMENT_TREND = 'engagement_trend';
    case CONTENT_MOMENTUM = 'content_momentum';
    case CHANNEL_MOMENTUM = 'channel_momentum';
    case TOPIC_MOMENTUM = 'topic_momentum';
    case MARKET_PACK_MOMENTUM = 'market_pack_momentum';
    case ORGANIC_GROWTH = 'organic_growth';
    case PERFORMANCE_OPPORTUNITY = 'performance_opportunity';
    case PERFORMANCE_RISK = 'performance_risk';
    case ANOMALY = 'anomaly';
    case DATA_QUALITY = 'data_quality';
    case INSUFFICIENT_DATA = 'insufficient_data';
    case CUSTOM = 'custom';

    public static function normalize(self|string $type): string
    {
        if ($type instanceof self) {
            return $type->value;
        }

        $normalized = str($type)->lower()->trim()->slug('_')->toString();

        return $normalized !== '' ? $normalized : self::CUSTOM->value;
    }
}
