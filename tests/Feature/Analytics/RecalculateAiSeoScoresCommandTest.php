<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recalculates ai seo scores and prints summary output', function () {
    $analyticsSite = createAiSeoCommandAnalyticsSite();

    DB::table('content_metrics')->insert([
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/a', 'url_key' => 'example.com/a', 'roi_score' => 82.4, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/b', 'url_key' => 'example.com/b', 'roi_score' => 45.0, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/c', 'url_key' => 'example.com/c', 'roi_score' => 12.3, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('content_ai_visibility')->insert([
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/a', 'url_key' => 'example.com/a', 'ai_visibility_score' => 5.0, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/b', 'url_key' => 'example.com/b', 'ai_visibility_score' => 50.0, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $analyticsSite->id, 'url' => 'https://example.com/c', 'url_key' => 'example.com/c', 'ai_visibility_score' => 120.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Artisan::call('stats:recalculate-ai-seo-scores');
    $output = Artisan::output();

    expect($output)->toContain('urls processed: 3')
        ->and($output)->toContain('min ai_seo_score:')
        ->and($output)->toContain('max ai_seo_score:')
        ->and($output)->toContain('avg ai_seo_score:');

    expect(DB::table('content_ai_seo_scores')->count())->toBe(3);

    $normalization = DB::table('stats_metric_settings')
        ->where('metric_key', 'ai_visibility_normalization')
        ->first();

    expect($normalization)->not->toBeNull();
    expect($normalization->settings_json)->not->toBeNull();
});

it('recalculates ai seo scores for a single analytics site when --site is provided', function () {
    $siteA = createAiSeoCommandAnalyticsSite();
    $siteB = createAiSeoCommandAnalyticsSite();

    DB::table('content_metrics')->insert([
        ['analytics_site_id' => $siteA->id, 'url' => 'https://example.com/site-a', 'url_key' => 'example.com/site-a', 'roi_score' => 70.0, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $siteB->id, 'url' => 'https://example.com/site-b', 'url_key' => 'example.com/site-b', 'roi_score' => 30.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('content_ai_visibility')->insert([
        ['analytics_site_id' => $siteA->id, 'url' => 'https://example.com/site-a', 'url_key' => 'example.com/site-a', 'ai_visibility_score' => 80.0, 'created_at' => now(), 'updated_at' => now()],
        ['analytics_site_id' => $siteB->id, 'url' => 'https://example.com/site-b', 'url_key' => 'example.com/site-b', 'ai_visibility_score' => 10.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Artisan::call('stats:recalculate-ai-seo-scores', ['--site' => (string) $siteA->id]);

    $siteARow = DB::table('content_ai_seo_scores')
        ->where('analytics_site_id', (string) $siteA->id)
        ->where('url_key', 'example.com/site-a')
        ->first();

    $siteBRow = DB::table('content_ai_seo_scores')
        ->where('analytics_site_id', (string) $siteB->id)
        ->where('url_key', 'example.com/site-b')
        ->first();

    expect($siteARow)->not->toBeNull();
    expect($siteBRow)->toBeNull();
});

function createAiSeoCommandAnalyticsSite(): AnalyticsSite
{
    $organization = Organization::query()->create([
        'name' => 'AI SEO Command Org',
        'slug' => 'ai-seo-command-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'AI SEO Command Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'AI SEO Command Site',
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
