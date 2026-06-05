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
use App\Services\DraftComparison\DraftComparisonScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds draft comparison metrics and persists score rows per variant', function () {
    Queue::fake();

    $organization = Organization::query()->create([
        'name' => 'Draft Compare Scoring Org',
        'slug' => 'draft-compare-scoring-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Scoring Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scoring Site',
        'site_url' => 'https://draft-compare-scoring.example.com',
        'allowed_domains' => ['draft-compare-scoring.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Scoring User',
        'email' => 'draft-compare-scoring-' . Str::random(5) . '@example.com',
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
        'title' => 'Scoring brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'ai seo score',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_PROCESSING,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Scoring draft',
        'seo_title' => 'AI SEO score guide for teams',
        'seo_meta_description' => 'Learn how to improve AI SEO score with practical, measurable content workflows and structured optimization steps for teams.',
        'seo_h1' => 'How to improve AI SEO score',
        'seo_canonical' => 'https://draft-compare-scoring.example.com/ai-seo-score-guide',
        'output_type' => 'kb_article',
        'content_html' => '<h1>How to improve AI SEO score</h1><h2>Intro</h2><p>According to benchmark data, teams improve visibility by 24% when they align content structure and measurable SEO goals.</p><h2>Actions</h2><p>Book a demo and start your trial today to improve conversion outcomes.</p>',
        'meta' => [
            'primary_keyword' => 'ai seo score',
            'secondary_keywords' => ['visibility tracking', 'conversion outcomes'],
            'key_points' => ['measurable SEO goals', 'content structure'],
        ],
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_PROCESSING,
        'draft_id' => $draft->id,
    ]);

    $service = app(DraftComparisonScoringService::class);

    $result = $service->evaluateDraft($draft);

    $metrics = (array) data_get($result, 'metrics', []);
    $scoreRows = (array) data_get($result, 'score_rows', []);

    expect(array_keys($metrics))->toContain(
        'word_count',
        'reading_time',
        'seo_score',
        'ai_seo_score',
        'readability_score',
        'brand_voice_match',
        'cta_strength',
        'structure_quality',
        'topical_coverage',
        'entity_coverage',
        'factual_confidence',
        'conversion_focus',
    );

    expect($scoreRows)->toHaveCount(12);

    $service->replaceVariantScores($variant, $scoreRows);

    $persisted = DraftComparisonScore::query()
        ->where('draft_comparison_variant_id', $variant->id)
        ->get()
        ->keyBy('metric_key');

    expect($persisted)->toHaveCount(12)
        ->and($persisted->has('seo_score'))->toBeTrue()
        ->and($persisted->has('ai_seo_score'))->toBeTrue()
        ->and($persisted->has('conversion_focus'))->toBeTrue()
        ->and((string) $persisted->get('word_count')?->source_type)->toBe('derived')
        ->and((string) $persisted->get('seo_score')?->source_type)->toBe('heuristic')
        ->and((string) $persisted->get('ai_seo_score')?->source_type)->toBe('heuristic')
        ->and(trim((string) $persisted->get('seo_score')?->explanation))->not->toBe('');
});
