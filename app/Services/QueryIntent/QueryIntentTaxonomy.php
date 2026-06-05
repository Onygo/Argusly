<?php

namespace App\Services\QueryIntent;

class QueryIntentTaxonomy
{
    public const INTENTS = [
        'informational',
        'commercial',
        'transactional',
        'navigational',
        'implementation',
        'comparison',
        'migration',
        'risk_evaluation',
    ];

    public const FUNNEL_STAGES = [
        'awareness',
        'consideration',
        'decision',
        'retention',
    ];

    public const BUYER_ROLES = [
        'marketers',
        'developers',
        'founders',
        'operations',
        'enterprise_buyers',
    ];

    public const URGENCY_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public const BUSINESS_IMPACT_LEVELS = [
        'low',
        'medium',
        'high',
        'strategic',
    ];
}
