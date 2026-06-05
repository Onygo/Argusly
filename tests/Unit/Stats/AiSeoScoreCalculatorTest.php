<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Stats\AiSeoScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes visibility using p05/p95 clamp and linear scale', function () {
    $calculator = app(AiSeoScoreCalculator::class);
    [$p05, $p95] = $calculator->computeNormalizationBounds([10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);

    expect($calculator->normalizeVisibilityScore($p05 - 5, $p05, $p95))->toBe(0.0);
    expect($calculator->normalizeVisibilityScore($p95 + 5, $p05, $p95))->toBe(100.0);

    $midpoint = ($p05 + $p95) / 2;
    $normalizedMid = $calculator->normalizeVisibilityScore($midpoint, $p05, $p95);

    expect($normalizedMid)->toBeGreaterThan(49.0)
        ->and($normalizedMid)->toBeLessThan(51.0);
});

it('calculates ai seo score from configured weights', function () {
    $analyticsSite = createAiSeoAnalyticsSite();

    DB::table('content_metrics')->insert([
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/a', 'url_key' => 'example.com/a', 'roi_score' => 80, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/b', 'url_key' => 'example.com/b', 'roi_score' => 20, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('content_ai_visibility')->insert([
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/a', 'url_key' => 'example.com/a', 'ai_visibility_score' => 10, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/b', 'url_key' => 'example.com/b', 'ai_visibility_score' => 90, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $calculator = app(AiSeoScoreCalculator::class);
    $summary = $calculator->recalculate();

    expect($summary['processed'])->toBe(2);

    $rowA = DB::table('content_ai_seo_scores')->where('url', 'https://example.com/a')->first();
    $rowB = DB::table('content_ai_seo_scores')->where('url', 'https://example.com/b')->first();

    expect($rowA)->not->toBeNull();
    expect($rowB)->not->toBeNull();

    $expectedA = round(((80 * 0.55) + ((float) $rowA->ai_visibility_score_normalized * 0.45)), 2);
    $expectedB = round(((20 * 0.55) + ((float) $rowB->ai_visibility_score_normalized * 0.45)), 2);

    expect((float) $rowA->ai_seo_score)->toBe($expectedA);
    expect((float) $rowB->ai_seo_score)->toBe($expectedB);
    expect((string) $rowA->formula_version)->toBe('ai_seo_v1');
    expect((string) $rowA->analytics_site_id)->toBe((string) $analyticsSite->id);
    expect((string) $rowA->url_key)->toBe('example.com/a');
});

it('upserts ai seo score rows by url', function () {
    $siteId = createAiSeoAnalyticsSite()->id;

    DB::table('content_metrics')->insert([
        'analytics_site_id' => $siteId,
        'url' => 'https://example.com/upsert',
        'url_key' => 'example.com/upsert',
        'roi_score' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_visibility')->insert([
        'analytics_site_id' => $siteId,
        'url' => 'https://example.com/upsert',
        'url_key' => 'example.com/upsert',
        'ai_visibility_score' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $calculator = app(AiSeoScoreCalculator::class);
    $calculator->recalculate();

    $firstScore = (float) DB::table('content_ai_seo_scores')->where('url', 'https://example.com/upsert')->value('ai_seo_score');

    DB::table('content_metrics')
        ->where('url', 'https://example.com/upsert')
        ->update(['roi_score' => 90, 'updated_at' => now()]);

    $calculator->recalculate();

    $rows = DB::table('content_ai_seo_scores')->where('url', 'https://example.com/upsert')->get();
    $secondScore = (float) ($rows->first()->ai_seo_score ?? 0);

    expect($rows->count())->toBe(1);
    expect($secondScore)->not->toBe($firstScore);
});

it('handles partial ai seo score inputs gracefully by rebalancing weights', function () {
    $siteId = createAiSeoAnalyticsSite()->id;

    DB::table('content_metrics')->insert([
        'analytics_site_id' => $siteId,
        'url' => 'https://example.com/roi-only',
        'url_key' => 'example.com/roi-only',
        'roi_score' => 77,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_visibility')->insert([
        [
            'analytics_site_id' => $siteId,
            'url' => 'https://example.com/vis-only',
            'url_key' => 'example.com/vis-only',
            'ai_visibility_score' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'analytics_site_id' => $siteId,
            'url' => 'https://example.com/vis-reference',
            'url_key' => 'example.com/vis-reference',
            'ai_visibility_score' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $calculator = app(AiSeoScoreCalculator::class);
    $calculator->recalculate((string) $siteId);

    $roiOnly = DB::table('content_ai_seo_scores')
        ->where('analytics_site_id', (string) $siteId)
        ->where('url_key', 'example.com/roi-only')
        ->first();

    $visOnly = DB::table('content_ai_seo_scores')
        ->where('analytics_site_id', (string) $siteId)
        ->where('url_key', 'example.com/vis-only')
        ->first();

    expect($roiOnly)->not->toBeNull();
    expect((float) $roiOnly->ai_seo_score)->toBe(77.0);
    expect(json_decode((string) $roiOnly->weights_json, true))->toBe([
        'content_roi' => 1,
        'ai_visibility_normalized' => 0,
    ]);

    expect($visOnly)->not->toBeNull();
    expect((float) $visOnly->ai_seo_score)->toBe((float) $visOnly->ai_visibility_score_normalized);
    expect(json_decode((string) $visOnly->weights_json, true))->toBe([
        'content_roi' => 0,
        'ai_visibility_normalized' => 1,
    ]);
});

function createAiSeoAnalyticsSite(): AnalyticsSite
{
    $organization = Organization::query()->create([
        'name' => 'AI SEO Stats Org',
        'slug' => 'ai-seo-stats-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'AI SEO Stats Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'AI SEO Stats Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);
}
