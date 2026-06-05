<?php

use App\Models\Brief;
use App\Services\DraftComparison\DraftComparisonCreditEstimator;

it('estimates draft comparison credits with provider tiers and optional scoring and hybrid costs', function () {
    config()->set('credits.generation_pricing.article.baseline_output_tokens', 8000);
    config()->set('credits.generation_pricing.article.baseline_credits', 10);
    config()->set('credits.generation_pricing.article.step_tokens', 2000);
    config()->set('credits.generation_pricing.article.step_credits', 2);
    config()->set('credits.generation_pricing.article.max_credits', 50);

    config()->set('llm.pricing.token_factor.openai', 1.0);
    config()->set('llm.pricing.token_factor.anthropic', 1.5);
    config()->set('credits.draft_compare.model_tier_multipliers', [
        'mini' => 1.0,
        'sonnet' => 1.0,
    ]);
    config()->set('credits.draft_compare.scoring_credits_per_variant', 1);
    config()->set('credits.draft_compare.hybrid_credit_multiplier', 1.0);
    config()->set('credits.draft_compare.hybrid_scoring_credits', 0);
    config()->set('credits.draft_compare.estimated_input_to_output_ratio', 0.5);

    $brief = new Brief([
        'output_type' => 'kb_article',
    ]);

    $estimate = app(DraftComparisonCreditEstimator::class)->estimateForComparison(
        brief: $brief,
        selections: [
            ['provider' => 'openai', 'model' => 'gpt-4.1-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
        ],
        requestedMaxOutputTokens: 10000,
        includeScoring: true,
        includeHybrid: true,
    );

    expect((int) $estimate['requested_max_output_tokens'])->toBe(10000)
        ->and((int) $estimate['requested_model_count'])->toBe(2)
        ->and((int) $estimate['per_model_baseline_credits'])->toBe(12)
        ->and((int) $estimate['scoring_credit_cost'])->toBe(2)
        ->and((int) $estimate['hybrid_credit_cost'])->toBe(12)
        ->and((int) $estimate['estimated_credit_cost'])->toBe(44)
        ->and((int) $estimate['estimated_output_tokens'])->toBe(30000)
        ->and((int) $estimate['estimated_input_tokens'])->toBe(15000);

    $variants = collect((array) $estimate['variants'])->values();

    expect((int) data_get($variants->get(0), 'estimated_credit_cost'))->toBe(12)
        ->and((int) data_get($variants->get(1), 'estimated_credit_cost'))->toBe(18);
});
