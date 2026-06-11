<?php

return [
    'enabled' => env('ARGUSLY_SIGNAL_INTELLIGENCE_ENABLED', true),

    'queue' => env(
        'ARGUSLY_SIGNAL_INTELLIGENCE_QUEUE',
        'intelligence'
    ),

    'retention_days' => env(
        'ARGUSLY_SIGNAL_RETENTION_DAYS',
        180
    ),

    'score_defaults' => [
        'confidence' => 50,
        'impact' => 50,
        'risk' => 50,
        'opportunity' => 50,
    ],

    'scoring' => [
        'signal_strength_weights' => [
            'signal_strength' => 0.35,
            'confidence' => 0.25,
            'impact' => 0.20,
            'urgency' => 0.10,
            'risk_or_opportunity' => 0.10,
        ],
        'brand_visibility_weights' => [
            'strength' => 0.40,
            'confidence' => 0.25,
            'impact' => 0.20,
            'presence_bonus' => 0.15,
        ],
        'competitor_pressure_weights' => [
            'strength' => 0.35,
            'risk' => 0.25,
            'impact' => 0.20,
            'frequency' => 0.20,
        ],
        'trend_velocity_weights' => [
            'growth' => 0.50,
            'frequency' => 0.25,
            'source_diversity' => 0.15,
            'recency' => 0.10,
        ],
        'risk_weights' => [
            'risk' => 0.40,
            'severity' => 0.25,
            'negative_sentiment' => 0.20,
            'frequency' => 0.15,
        ],
        'opportunity_readiness_weights' => [
            'opportunity' => 0.35,
            'impact' => 0.25,
            'confidence' => 0.25,
            'frequency' => 0.15,
        ],
    ],
];
