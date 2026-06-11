<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalScoreType;
use App\Enums\SignalSeverity;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\SignalDetection;

it('defines the approved signal intelligence enum values', function (): void {
    expect(SignalEntityType::values())->toBe([
        'brand',
        'competitor',
        'company',
        'person',
        'product',
        'topic',
        'domain',
        'source',
    ]);

    expect(SignalSourceType::values())->toContain('rss_feed', 'llm_tracking', 'linkedin', 'search_trend');
    expect(SignalCategory::values())->toContain('mention', 'brand_visibility', 'risk', 'ai_visibility');
    expect(SignalType::values())->toContain('brand_mentioned', 'competitor_dominance', 'risk_declining_visibility');
    expect(SignalSeverity::values())->toBe(['info', 'low', 'medium', 'high', 'critical']);
    expect(SignalStatus::values())->toBe(['new', 'processing', 'detected', 'reviewing', 'published', 'dismissed', 'resolved', 'archived']);
    expect(SignalScoreType::values())->toContain('brand_visibility', 'competitor_pressure', 'source_quality');
    expect(OpportunitySignalSource::values())->toContain('signal_intelligence', 'competitor_intelligence');
    expect(OpportunityCategory::values())->toContain('brand_visibility', 'ai_visibility_opportunity', 'competitor_movement');
    expect(SignalDetection::categories())->toBe([
        'brand_monitoring',
        'competitor_monitoring',
        'trend_detection',
        'opportunity_detection',
        'risk_detection',
        'feed_processing',
    ]);
});
