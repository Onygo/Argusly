<?php

use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\AnalyticsSite;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\View\Presenters\ContentIndexTreePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders dashboard performance widgets with tracked values and stale ai seo fallback', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentPerformanceWidgetContext();

    $contentA = createTrackedContent($workspace, $site, 'Dashboard metrics A', '/blog/dashboard-metrics-a');
    $contentB = createTrackedContent($workspace, $site, 'Dashboard metrics B', '/blog/dashboard-metrics-b');

    seedPerformanceMetrics(
        analyticsSite: $analyticsSite,
        content: $contentA,
        roiScore: 80.0,
        aiVisibilityScore: 40.0,
        aiSeoScore: 71.2,
        metricsUpdatedAt: now()->subMinutes(8),
        visibilityUpdatedAt: now()->subMinutes(6),
        seoCalculatedAt: now()->subMinutes(4),
        seoContentMetricsUpdatedAt: now()->subMinutes(8),
        seoVisibilityUpdatedAt: now()->subMinutes(6),
    );

    seedPerformanceMetrics(
        analyticsSite: $analyticsSite,
        content: $contentB,
        roiScore: 60.0,
        aiVisibilityScore: 30.0,
        aiSeoScore: 54.0,
        metricsUpdatedAt: now()->subMinutes(2),
        visibilityUpdatedAt: now()->subMinutes(3),
        seoCalculatedAt: now()->subMinutes(12),
        seoContentMetricsUpdatedAt: now()->subMinutes(20),
        seoVisibilityUpdatedAt: now()->subMinutes(20),
    );

    $response = $this->actingAs($user)->get(route('app.dashboard'));

    $response
        ->assertOk()
        ->assertSee('Recent Results')
        ->assertSee('Content ROI')
        ->assertSee('AI Visibility')
        ->assertSee('AI SEO Score')
        ->assertSee('Latest Content');

    $summary = $response->viewData('performanceSummary');
    expect(data_get($summary, 'content_roi.value'))->toBe(70.0)
        ->and(data_get($summary, 'ai_visibility.value'))->toBe(35.0)
        ->and(data_get($summary, 'ai_seo_score.value'))->toBe(71.2);
});

it('renders the active LinkedIn publication queue on the dashboard', function (): void {
    [$user, $workspace] = makeContentPerformanceWidgetContext();

    $campaign = Campaign::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'name' => 'Agentic Marketing Launch',
        'slug' => 'agentic-marketing-launch-'.Str::random(6),
        'status' => 'active',
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN,
        'account_type' => 'person',
        'display_name' => 'Ricardo LinkedIn',
        'status' => 'connected',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP,
        'status' => SocialPostVariantStatus::SCHEDULED,
        'variant_number' => 1,
        'body' => 'A scheduled LinkedIn post for the dashboard queue.',
    ]);

    $scheduledFor = now()->addDay()->setTime(18, 0);

    SocialPublication::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN,
        'status' => SocialPublicationStatus::SCHEDULED,
        'scheduled_for' => $scheduledFor,
    ]);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Publication queue')
        ->assertSee('Ready to go live')
        ->assertSee('Agentic Marketing Launch')
        ->assertSee('Ricardo LinkedIn')
        ->assertSee($scheduledFor->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i'))
        ->assertSee('Open distribution');
});

it('groups latest dashboard content by article and keeps locale rows collapsed by default', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentPerformanceWidgetContext();

    $source = createTrackedContent($workspace, $site, 'AI Cybersecurity Architecture', '/blog/ai-cybersecurity-architecture');
    $source->update([
        'language' => 'nl',
        'translation_source_locale' => null,
        'is_source_locale' => true,
    ]);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI Cybersecurity Architecture EN',
        'language' => 'en',
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ]);

    seedPerformanceMetrics(
        analyticsSite: $analyticsSite,
        content: $source->fresh(),
        roiScore: 78.0,
        aiVisibilityScore: 41.0,
        aiSeoScore: 70.0,
        metricsUpdatedAt: now()->subMinutes(4),
        visibilityUpdatedAt: now()->subMinutes(3),
        seoCalculatedAt: now()->subMinutes(2),
        seoContentMetricsUpdatedAt: now()->subMinutes(4),
        seoVisibilityUpdatedAt: now()->subMinutes(3),
    );

    $response = $this->actingAs($user)->get(route('app.dashboard'));

    $response->assertOk()
        ->assertSee('Latest Content')
        ->assertSee('data-dashboard-content-tree-toggle', false)
        ->assertSee('SRC NL')
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('href="'.route('app.content.show', $source).'"', false)
        ->assertSee('href="'.route('app.content.show', $variant).'"', false);

    $tree = collect($response->viewData('recentContentTree'));

    expect($tree)->toHaveCount(1)
        ->and(data_get($tree->first(), 'title'))->toBe('AI Cybersecurity Architecture')
        ->and(data_get($tree->first(), 'summary.available_locales'))->toBe(2);
});

it('uses automation locale scope when summarizing missing content locales', function () {
    [$user, $workspace, $site] = makeContentPerformanceWidgetContext();
    $workspace->forceFill(['enabled_content_languages' => ['en', 'nl']])->save();

    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'English only automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 1,
        'generation_frequency_unit' => 'weeks',
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en'],
        'include_translation' => false,
        'topic_scope' => 'AI visibility roadmap',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'automation_id' => $automation->id,
        'title' => 'How to Implement AI Visibility Roadmap',
        'language' => 'en',
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ]);

    $content->load(['workspace', 'automation']);
    $tree = ContentIndexTreePresenter::present(collect([$content]), collect([$content]));

    $article = data_get($tree->first(), 'articles.0');
    expect(data_get($article, 'summary.expected_locales'))->toBe(1)
        ->and(data_get($article, 'summary.available_locales'))->toBe(1)
        ->and(data_get($article, 'summary.missing_translations'))->toBe(0)
        ->and(data_get($article, 'summary.status_reasons'))->not->toContain('Missing locale');

    $actionContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI Visibility Roadmap Guide',
        'language' => 'en',
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'automation',
        'origin_type' => 'automation',
        'publish_status' => 'draft',
        'external_key' => (string) Str::uuid(),
    ]);

    $actionContent->load('workspace');
    $actionTree = ContentIndexTreePresenter::present(collect([$actionContent]), collect([$actionContent]));

    expect(data_get($actionTree->first(), 'articles.0.summary.expected_locales'))->toBe(1)
        ->and(data_get($actionTree->first(), 'articles.0.summary.status_reasons'))->not->toContain('Missing locale');
});

it('limits the dashboard latest content widget to five parent content items', function () {
    [$user, $workspace, $site] = makeContentPerformanceWidgetContext();

    foreach (range(1, 6) as $index) {
        $source = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Dashboard Parent '.$index,
            'language' => 'nl',
            'translation_source_locale' => null,
            'is_source_locale' => true,
            'type' => 'article',
            'status' => 'published',
            'source' => 'manual',
            'publish_status' => 'published',
            'published_url' => 'https://content-performance.example.com/blog/dashboard-parent-'.$index,
            'external_key' => (string) Str::uuid(),
        ]);
        $source->forceFill([
            'created_at' => now()->subMinutes(7 - $index),
            'updated_at' => now()->subMinutes(7 - $index),
        ])->saveQuietly();

        $variant = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'title' => 'Dashboard Parent '.$index.' EN',
            'language' => 'en',
            'translation_source_content_id' => $source->id,
            'translation_source_locale' => 'nl',
            'is_source_locale' => false,
            'type' => 'article',
            'status' => 'draft',
            'source' => 'translation',
            'publish_status' => 'draft',
            'external_key' => (string) Str::uuid(),
        ]);
        $variant->forceFill([
            'created_at' => now()->subMinutes(7 - $index),
            'updated_at' => now()->subMinutes(7 - $index),
        ])->saveQuietly();
    }

    $response = $this->actingAs($user)->get(route('app.dashboard'));

    $response->assertOk();

    $tree = collect($response->viewData('recentContentTree'));
    $titles = $tree->pluck('title')->all();

    expect($tree)->toHaveCount(5)
        ->and($titles)->toBe([
            'Dashboard Parent 6',
            'Dashboard Parent 5',
            'Dashboard Parent 4',
            'Dashboard Parent 3',
            'Dashboard Parent 2',
        ]);
});

it('shows performance snapshot and pending recalculation state on content detail overview', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentPerformanceWidgetContext();

    $content = createTrackedContent($workspace, $site, 'Detail Metrics Content', '/blog/detail-metrics-content');

    seedPerformanceMetrics(
        analyticsSite: $analyticsSite,
        content: $content,
        roiScore: 76.0,
        aiVisibilityScore: 44.0,
        aiSeoScore: 62.5,
        metricsUpdatedAt: now()->subMinutes(1),
        visibilityUpdatedAt: now()->subMinutes(1),
        seoCalculatedAt: now()->subMinutes(15),
        seoContentMetricsUpdatedAt: now()->subMinutes(30),
        seoVisibilityUpdatedAt: now()->subMinutes(30),
    );

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'overview']))
        ->assertOk()
        ->assertSee('Performance Snapshot')
        ->assertSee('Content ROI')
        ->assertSee('AI Visibility')
        ->assertSee('AI SEO Score')
        ->assertSee('Score pending recalculation');
});

it('shows publish-first empty state for content performance column when content is not published', function () {
    [$user, $workspace, $site] = makeContentPerformanceWidgetContext();

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'No Publish Metrics',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
    ]);

    $this->actingAs($user)
        ->get(route('app.content.index'))
        ->assertOk()
        ->assertSee('Performance')
        ->assertSee('Publish to start tracking.');
});

function makeContentPerformanceWidgetContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Performance Org',
        'slug' => 'content-performance-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Performance BV',
        'billing_address_line1' => 'Teststraat 42',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Performance Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Performance Site',
        'site_url' => 'https://content-performance.example.com',
        'base_url' => 'https://content-performance.example.com',
        'allowed_domains' => ['content-performance.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['content-performance.example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-performance-plan'],
        [
            'name' => 'Content Performance Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    OnboardingState::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'phase' => OnboardingState::PHASE_ACTIVATED,
        'registered_at' => now()->subDays(2),
        'completed_steps_json' => ['intent', 'company_profile', 'connect_site'],
    ]);

    return [$user, $workspace, $site, $analyticsSite];
}

function createTrackedContent(Workspace $workspace, ClientSite $site, string $title, string $path): Content
{
    $baseUrl = rtrim((string) ($site->base_url ?: $site->site_url), '/');

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'published_url' => $baseUrl . $path,
        'external_key' => (string) Str::uuid(),
    ]);

    return $content->fresh();
}

function seedPerformanceMetrics(
    AnalyticsSite $analyticsSite,
    Content $content,
    float $roiScore,
    float $aiVisibilityScore,
    float $aiSeoScore,
    Carbon $metricsUpdatedAt,
    Carbon $visibilityUpdatedAt,
    Carbon $seoCalculatedAt,
    Carbon $seoContentMetricsUpdatedAt,
    Carbon $seoVisibilityUpdatedAt,
): void {
    $url = trim((string) ($content->published_url ?? ''));
    $urlKey = trim((string) ($content->canonical_url_key ?: $content->publish_url_key));

    if ($url === '' || $urlKey === '') {
        throw new RuntimeException('Missing published url data for content metric seeding.');
    }

    DB::table('content_metrics')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => $url,
        'url_key' => $urlKey,
        'roi_score' => $roiScore,
        'avg_scroll_depth' => 58.2,
        'max_scroll_depth' => 91,
        'avg_read_time' => 95.4,
        'median_read_time' => 88.2,
        'engaged_rate' => 0.31,
        'read_through_rate' => 0.27,
        'estimated_read_time' => 120,
        'created_at' => $metricsUpdatedAt,
        'updated_at' => $metricsUpdatedAt,
    ]);

    DB::table('content_ai_visibility')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => $url,
        'url_key' => $urlKey,
        'llm_citations' => 4,
        'brand_mentions' => 2,
        'competitor_mentions' => 1,
        'ai_visibility_score' => $aiVisibilityScore,
        'last_checked_at' => $visibilityUpdatedAt,
        'created_at' => $visibilityUpdatedAt,
        'updated_at' => $visibilityUpdatedAt,
    ]);

    $scoreRow = [
        'url' => $url,
        'url_hash' => hash('sha256', $url),
        'content_roi_score' => $roiScore,
        'ai_visibility_score' => $aiVisibilityScore,
        'ai_visibility_score_normalized' => $aiVisibilityScore,
        'ai_seo_score' => $aiSeoScore,
        'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45], JSON_THROW_ON_ERROR),
        'calculated_at' => $seoCalculatedAt,
        'created_at' => $seoCalculatedAt,
        'updated_at' => $seoCalculatedAt,
    ];

    if (Schema::hasColumn('content_ai_seo_scores', 'analytics_site_id')) {
        $scoreRow['analytics_site_id'] = $analyticsSite->id;
    }

    if (Schema::hasColumn('content_ai_seo_scores', 'url_key')) {
        $scoreRow['url_key'] = $urlKey;
    }

    if (Schema::hasColumn('content_ai_seo_scores', 'content_metrics_updated_at')) {
        $scoreRow['content_metrics_updated_at'] = $seoContentMetricsUpdatedAt;
    }

    if (Schema::hasColumn('content_ai_seo_scores', 'ai_visibility_updated_at')) {
        $scoreRow['ai_visibility_updated_at'] = $seoVisibilityUpdatedAt;
    }

    if (Schema::hasColumn('content_ai_seo_scores', 'formula_version')) {
        $scoreRow['formula_version'] = 'ai_seo_v1';
    }

    if (Schema::hasColumn('content_ai_seo_scores', 'inputs_json')) {
        $scoreRow['inputs_json'] = json_encode([
            'has_content_roi' => true,
            'has_ai_visibility' => true,
        ], JSON_THROW_ON_ERROR);
    }

    DB::table('content_ai_seo_scores')->insert($scoreRow);
}
