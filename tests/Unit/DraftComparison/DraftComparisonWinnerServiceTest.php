<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\DraftComparison\DraftComparisonWinnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recommends a winner variant using configured weighted metrics', function () {
    config()->set('credits.draft_compare.winner_weights', [
        'seo_score' => 20,
        'ai_seo_score' => 15,
        'brand_voice_match' => 20,
        'structure_quality' => 15,
        'readability_score' => 10,
        'cta_strength' => 10,
        'conversion_focus' => 10,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Draft Compare Winner Org',
        'slug' => 'draft-compare-winner-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Winner Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Winner Site',
        'site_url' => 'https://draft-compare-winner.example.com',
        'allowed_domains' => ['draft-compare-winner.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Winner User',
        'email' => 'draft-compare-winner-' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Winner brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Winner candidate A',
        'output_type' => 'kb_article',
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Winner candidate B',
        'output_type' => 'kb_article',
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
    ]);

    $metricsA = [
        'seo_score' => 91,
        'ai_seo_score' => 88,
        'brand_voice_match' => 87,
        'structure_quality' => 85,
        'readability_score' => 72,
        'cta_strength' => 65,
        'conversion_focus' => 68,
        'word_count' => 900,
    ];

    $metricsB = [
        'seo_score' => 75,
        'ai_seo_score' => 70,
        'brand_voice_match' => 96,
        'structure_quality' => 72,
        'readability_score' => 65,
        'cta_strength' => 95,
        'conversion_focus' => 94,
        'word_count' => 550,
    ];

    foreach ($metricsA as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantA->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'numeric_score' => $value,
            'explanation' => 'A score',
        ]);
    }

    foreach ($metricsB as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantB->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'numeric_score' => $value,
            'explanation' => 'B score',
        ]);
    }

    $recommendation = app(DraftComparisonWinnerService::class)->recommend($comparison);

    expect((string) data_get($recommendation, 'version'))->toBe('draft_compare_winner_v1')
        ->and((string) data_get($recommendation, 'suggested_winner.variant_id'))->toBe((string) $variantA->id)
        ->and((string) data_get($recommendation, 'suggested_winner.draft_id'))->toBe((string) $draftA->id)
        ->and((string) data_get($recommendation, 'best_for_brand_voice.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($recommendation, 'best_conversion_focused_option.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($recommendation, 'best_concise_option.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($recommendation, 'why_it_won'))->toContain('Highest weighted score');
});

it('uses workspace winner-weight entitlement overrides when present', function () {
    config()->set('credits.draft_compare.winner_weights', [
        'seo_score' => 20,
        'ai_seo_score' => 15,
        'brand_voice_match' => 20,
        'structure_quality' => 15,
        'readability_score' => 10,
        'cta_strength' => 10,
        'conversion_focus' => 10,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Draft Compare Winner Override Org',
        'slug' => 'draft-compare-winner-override-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Winner Override Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Winner Override Site',
        'site_url' => 'https://draft-compare-winner-override.example.com',
        'allowed_domains' => ['draft-compare-winner-override.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Winner Override User',
        'email' => 'draft-compare-winner-override-' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Winner override brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Override candidate A',
        'output_type' => 'kb_article',
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Override candidate B',
        'output_type' => 'kb_article',
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
    ]);

    foreach ([
        'seo_score' => 91,
        'ai_seo_score' => 88,
        'brand_voice_match' => 87,
        'structure_quality' => 85,
        'readability_score' => 72,
        'cta_strength' => 65,
        'conversion_focus' => 68,
    ] as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantA->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'numeric_score' => $value,
            'explanation' => 'A score',
        ]);
    }

    foreach ([
        'seo_score' => 75,
        'ai_seo_score' => 70,
        'brand_voice_match' => 96,
        'structure_quality' => 72,
        'readability_score' => 65,
        'cta_strength' => 95,
        'conversion_focus' => 94,
    ] as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantB->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'numeric_score' => $value,
            'explanation' => 'B score',
        ]);
    }

    WorkspaceEntitlement::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'feature_key' => 'draft_compare_winner_weights',
        'value_type' => 'json',
        'value_json' => [
            'seo_score' => 0,
            'ai_seo_score' => 0,
            'brand_voice_match' => 0,
            'structure_quality' => 0,
            'readability_score' => 0,
            'cta_strength' => 40,
            'conversion_focus' => 60,
        ],
        'source' => 'manual_override',
        'effective_at' => now()->subMinute(),
        'refreshed_at' => now(),
    ]);

    $recommendation = app(DraftComparisonWinnerService::class)->recommend($comparison);

    expect((string) data_get($recommendation, 'weights_source'))->toBe('workspace_entitlement')
        ->and((string) data_get($recommendation, 'suggested_winner.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($recommendation, 'suggested_winner.draft_id'))->toBe((string) $draftB->id);
});
