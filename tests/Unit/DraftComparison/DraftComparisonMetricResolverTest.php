<?php

use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Services\DraftComparison\DraftComparisonMetricResolver;
use Illuminate\Support\Collection;

it('prefers persisted score rows over legacy item metrics for variant resolution', function () {
    $resolver = app(DraftComparisonMetricResolver::class);

    $variant = new DraftComparisonVariant([
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
    ]);
    $variant->setRelation('scores', new Collection([
        new DraftComparisonScore([
            'metric_key' => 'seo_score',
            'numeric_score' => 88.2,
        ]),
    ]));

    $metrics = $resolver->metricsForVariant($variant, [
        'openai:gpt-4.1-mini' => ['seo_score' => 52.0],
    ]);

    expect((float) ($metrics['seo_score'] ?? 0.0))->toBe(88.2);
});

it('normalizes legacy comparison item metric keys and provider-model lookup', function () {
    $resolver = app(DraftComparisonMetricResolver::class);

    $comparison = new DraftComparison();
    $comparison->setRelation('items', new Collection([
        new DraftComparisonItem([
            'provider' => 'OpenAI',
            'model' => 'gpt-4.1-mini',
            'metrics' => [
                'reading_time_minutes' => 5,
                'word_count' => 920,
            ],
        ]),
    ]));

    $legacy = $resolver->legacyMetricsByProviderModel($comparison);

    expect(array_key_exists('openai:gpt-4.1-mini', $legacy))->toBeTrue();

    $variant = new DraftComparisonVariant([
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
    ]);
    $variant->setRelation('scores', new Collection());

    $metrics = $resolver->metricsForVariant($variant, $legacy);

    expect((float) ($metrics['reading_time'] ?? 0.0))->toBe(5.0)
        ->and((float) ($metrics['word_count'] ?? 0.0))->toBe(920.0);
});
