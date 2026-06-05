<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\SiteAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('computes learnings engagement metrics from tracked events', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    $canonicalUrl = 'https://example.com/articles/engagement-test';
    $path = '/articles/engagement-test';
    $baseTime = now()->subDay();

    for ($i = 1; $i <= 10; $i++) {
        createLearningsMetricEvent($analyticsSite, 'pageview', $canonicalUrl, $path, "pv-{$i}", $baseTime->copy()->addSeconds($i));
    }

    for ($i = 1; $i <= 4; $i++) {
        createLearningsMetricEvent($analyticsSite, 'engaged', $canonicalUrl, $path, "eng-{$i}", $baseTime->copy()->addMinutes($i));
    }

    for ($i = 1; $i <= 2; $i++) {
        createLearningsMetricEvent($analyticsSite, 'read_through', $canonicalUrl, $path, "rt-{$i}", $baseTime->copy()->addHours($i));
    }

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $summary = $response->viewData('summary');

    expect($summary['total_views'])->toBe(10);
    expect($summary['total_engaged'])->toBe(4);
    expect($summary['total_read_through'])->toBe(2);
    expect($summary['engagement_rate'])->toBe(40);
});

it('keeps quick stats pageview totals unchanged when engagement events exist', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    $canonicalUrl = 'https://example.com/articles/quick-stats-regression';
    $path = '/articles/quick-stats-regression';
    $baseTime = now()->subDay();

    for ($i = 1; $i <= 10; $i++) {
        createLearningsMetricEvent($analyticsSite, 'pageview', $canonicalUrl, $path, "stats-pv-{$i}", $baseTime->copy()->addSeconds($i));
    }

    for ($i = 1; $i <= 4; $i++) {
        createLearningsMetricEvent($analyticsSite, 'engaged', $canonicalUrl, $path, "stats-eng-{$i}", $baseTime->copy()->addMinutes($i));
    }

    for ($i = 1; $i <= 2; $i++) {
        createLearningsMetricEvent($analyticsSite, 'read_through', $canonicalUrl, $path, "stats-rt-{$i}", $baseTime->copy()->addHours($i));
    }

    $response = $this->actingAs($user)
        ->get(route('app.sites.analytics.show', ['site' => $site, 'scope' => 'all']));

    $response->assertOk();
    $stats = $response->viewData('stats');

    expect($stats['pageviews_7d'])->toBe(10);
    expect($stats['pageviews_30d'])->toBe(10);
});

it('aggregates read_through counts in learnings for last 7 days', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    $canonicalUrl = 'https://example.com/articles/read-through-only';
    $path = '/articles/read-through-only';
    $baseTime = now()->subHours(12);

    for ($i = 1; $i <= 10; $i++) {
        createLearningsMetricEvent($analyticsSite, 'pageview', $canonicalUrl, $path, "rt-case-pv-{$i}", $baseTime->copy()->addSeconds($i));
    }

    for ($i = 1; $i <= 3; $i++) {
        createLearningsMetricEvent($analyticsSite, 'read_through', $canonicalUrl, $path, "rt-case-rt-{$i}", $baseTime->copy()->addMinutes($i));
    }

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $summary = $response->viewData('summary');

    expect($summary['total_views'])->toBe(10);
    expect($summary['total_read_through'])->toBe(3);
});

it('groups trending rows by normalized URL key', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    $eventTime = now()->subHours(6);
    $normalizedKey = 'example.com/article';

    $variants = [
        ['url' => 'https://example.com/Article/', 'canonical' => 'https://example.com/Article/'],
        ['url' => 'https://example.com/article?utm=source', 'canonical' => 'https://example.com/article?utm=source'],
        ['url' => 'https://example.com/article#top', 'canonical' => 'https://example.com/article#top'],
    ];

    foreach ($variants as $index => $variant) {
        AnalyticsEvent::query()->create([
            'analytics_site_id' => $analyticsSite->id,
            'event_type' => 'page_view',
            'visitor_hash' => hash('sha256', "variant-visitor-{$index}"),
            'session_hash' => hash('sha256', "variant-session-{$index}"),
            'path' => '/article',
            'path_hash' => hash('sha256', '/article'),
            'title' => 'Normalized Article',
            'host' => 'example.com',
            'url' => $variant['url'],
            'canonical_url' => $variant['canonical'],
            'canonical_url_hash' => hash('sha256', $variant['canonical']),
            'url_key' => $normalizedKey,
            'content_id' => null,
            'page_type' => 'other_page',
            'event_hash' => hash('sha256', "variant-event-{$index}"),
            'event_time' => $eventTime->copy()->addMinutes($index * 10),
            'received_at' => $eventTime->copy()->addMinutes($index * 10),
        ]);
    }

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $trending = $response->viewData('trending');

    expect($trending->count())->toBe(1);
    expect((int) $trending->first()['views'])->toBe(3);
});

it('renders learnings and analytics quick stats with ONLY_FULL_GROUP_BY enabled', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    forceOnlyFullGroupByForCurrentSession();

    $baseTime = now()->subHours(4);

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/mysql-strict-a',
        '/articles/mysql-strict-a',
        'strict-a-1',
        $baseTime->copy()
    );
    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/mysql-strict-a?utm=campaign',
        '/articles/mysql-strict-a',
        'strict-a-2',
        $baseTime->copy()->addMinute()
    );
    createLearningsMetricEvent(
        $analyticsSite,
        'engaged',
        'https://example.com/articles/mysql-strict-a',
        '/articles/mysql-strict-a',
        'strict-a-engaged',
        $baseTime->copy()->addMinutes(2)
    );
    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/mysql-strict-b',
        '/articles/mysql-strict-b',
        'strict-b-1',
        $baseTime->copy()->addMinutes(3)
    );

    $learningsResponse = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $learningsResponse->assertOk();

    $trending = $learningsResponse->viewData('trending');
    $summary = $learningsResponse->viewData('summary');

    expect($trending)->toHaveCount(2)
        ->and($trending->pluck('page_key')->values()->all())->toBe([
            'example.com/articles/mysql-strict-a',
            'example.com/articles/mysql-strict-b',
        ])
        ->and((int) $trending->first()['views'])->toBe(2)
        ->and((int) $summary['unique_pages'])->toBe(2);

    $analyticsResponse = $this->actingAs($user)
        ->get(route('app.sites.analytics.show', ['site' => $site, 'scope' => 'all']));

    $analyticsResponse->assertOk();

    $stats = $analyticsResponse->viewData('stats');

    expect($stats['pageviews_7d'])->toBe(3)
        ->and($stats['pageviews_30d'])->toBe(3)
        ->and($stats['article_daily']->pluck('page_key')->unique()->sort()->values()->all())->toBe([
            'example.com/articles/mysql-strict-a',
            'example.com/articles/mysql-strict-b',
        ]);
});

it('shows advanced content analytics fields on trending rows', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/advanced-metrics',
        '/articles/advanced-metrics',
        'advanced-metrics',
        now()->subMinutes(10)
    );

    DB::table('content_metrics')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => 'https://example.com/articles/advanced-metrics',
        'url_key' => 'example.com/articles/advanced-metrics',
        'avg_scroll_depth' => 66.6,
        'max_scroll_depth' => 100,
        'avg_read_time' => 72.4,
        'median_read_time' => 70.0,
        'engaged_rate' => 0.7,
        'read_through_rate' => 0.4,
        'estimated_read_time' => 180.0,
        'roi_score' => 74.2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_visibility')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => 'https://example.com/articles/advanced-metrics',
        'url_key' => 'example.com/articles/advanced-metrics',
        'llm_citations' => 3,
        'brand_mentions' => 9,
        'competitor_mentions' => 2,
        'ai_visibility_score' => 9.75,
        'last_checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_seo_scores')->insert([
        'url' => 'https://example.com/articles/advanced-metrics',
        'url_hash' => hash('sha256', 'https://example.com/articles/advanced-metrics'),
        'content_roi_score' => 74.2,
        'ai_visibility_score' => 9.75,
        'ai_visibility_score_normalized' => 62.0,
        'ai_seo_score' => 68.6,
        'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45]),
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $first = $response->viewData('trending')->first();

    expect($first['avg_scroll_depth'])->toBe(66.6)
        ->and($first['avg_read_time'])->toBe(72.4)
        ->and($first['roi_score'])->toBe(74.2)
        ->and($first['ai_visibility_score'])->toBe(9.8)
        ->and($first['ai_seo_score'])->toBe(68.6)
        ->and($first['is_ai_cited'])->toBeTrue();
});

it('sorts trending rows by ai seo score using stored aggregates', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/low-seo',
        '/articles/low-seo',
        'low-seo',
        now()->subMinutes(8)
    );

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/high-seo',
        '/articles/high-seo',
        'high-seo',
        now()->subMinutes(7)
    );

    DB::table('content_metrics')->insert([
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => 'https://example.com/articles/low-seo',
            'url_key' => 'example.com/articles/low-seo',
            'roi_score' => 35.0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => 'https://example.com/articles/high-seo',
            'url_key' => 'example.com/articles/high-seo',
            'roi_score' => 82.0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('content_ai_visibility')->insert([
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => 'https://example.com/articles/low-seo',
            'url_key' => 'example.com/articles/low-seo',
            'ai_visibility_score' => 20.0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => 'https://example.com/articles/high-seo',
            'url_key' => 'example.com/articles/high-seo',
            'ai_visibility_score' => 80.0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('content_ai_seo_scores')->insert([
        [
            'url' => 'https://example.com/articles/low-seo',
            'url_hash' => hash('sha256', 'https://example.com/articles/low-seo'),
            'content_roi_score' => 35.0,
            'ai_visibility_score' => 20.0,
            'ai_visibility_score_normalized' => 22.0,
            'ai_seo_score' => 29.2,
            'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'url' => 'https://example.com/articles/high-seo',
            'url_hash' => hash('sha256', 'https://example.com/articles/high-seo'),
            'content_roi_score' => 82.0,
            'ai_visibility_score' => 80.0,
            'ai_visibility_score_normalized' => 84.0,
            'ai_seo_score' => 82.9,
            'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7&sort=ai_seo_score');

    $response->assertOk();
    $trending = $response->viewData('trending');

    expect($trending)->toHaveCount(2);
    expect($trending[0]['path'])->toBe('https://example.com/articles/high-seo');
    expect($trending[0]['ai_seo_score'])->toBe(82.9);
    expect($trending[1]['path'])->toBe('https://example.com/articles/low-seo');
    expect($trending[1]['ai_seo_score'])->toBe(29.2);
});

it('loads trending advanced metrics without n+1 queries', function () {
    [, , $analyticsSite] = createLearningsMetricsContext();

    $seedRows = function (int $count, int $offset) use ($analyticsSite): void {
        $events = [];
        $contentMetrics = [];
        $aiVisibility = [];
        $aiSeoScores = [];

        for ($i = 0; $i < $count; $i++) {
            $index = $offset + $i;
            $slug = '/articles/n-plus-one-' . $index;
            $url = 'https://example.com' . $slug;
            $urlKey = 'example.com' . $slug;

            $events[] = [
                'analytics_site_id' => $analyticsSite->id,
                'event_type' => 'page_view',
                'visitor_hash' => hash('sha256', 'n1-v-' . $index),
                'session_hash' => hash('sha256', 'n1-s-' . $index),
                'path' => $slug,
                'path_hash' => hash('sha256', $slug),
                'title' => 'N+1 ' . $index,
                'host' => 'example.com',
                'url' => $url,
                'canonical_url' => $url,
                'canonical_url_hash' => hash('sha256', $url),
                'url_key' => $urlKey,
                'content_id' => null,
                'page_type' => 'other_page',
                'event_hash' => hash('sha256', 'n1-e-' . $index),
                'event_time' => now()->subMinutes($index + 1),
                'received_at' => now()->subMinutes($index + 1),
            ];

            $contentMetrics[] = [
                'analytics_site_id' => $analyticsSite->id,
                'url' => $url,
                'url_key' => $urlKey,
                'roi_score' => 55.0 + ($index % 30),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $aiVisibility[] = [
                'analytics_site_id' => $analyticsSite->id,
                'url' => $url,
                'url_key' => $urlKey,
                'llm_citations' => 1,
                'brand_mentions' => 2,
                'competitor_mentions' => 1,
                'ai_visibility_score' => 40.0 + ($index % 30),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $aiSeoScores[] = [
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'content_roi_score' => 55.0 + ($index % 30),
                'ai_visibility_score' => 40.0 + ($index % 30),
                'ai_visibility_score_normalized' => 45.0 + ($index % 30),
                'ai_seo_score' => 52.0 + ($index % 30),
                'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45]),
                'calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('analytics_events')->insert($events);
        DB::table('content_metrics')->insert($contentMetrics);
        DB::table('content_ai_visibility')->insert($aiVisibility);
        DB::table('content_ai_seo_scores')->insert($aiSeoScores);
    };

    $service = app(SiteAnalyticsService::class);

    $seedRows(1, 0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->getLearningsOverview($analyticsSite, 7, SiteAnalyticsService::SCOPE_ALL);
    $singleRowQueryCount = count(DB::getQueryLog());

    $seedRows(20, 100);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->getLearningsOverview($analyticsSite, 7, SiteAnalyticsService::SCOPE_ALL);
    $twentyOneRowQueryCount = count(DB::getQueryLog());

    expect($twentyOneRowQueryCount)->toBeLessThanOrEqual($singleRowQueryCount + 1);
});

it('formats last seen labels without fractional day values', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/label-check',
        '/articles/label-check',
        'label-check',
        now()->subDays(2)->setTime(12, 0)
    );

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $first = $response->viewData('trending')->first();

    expect((string) $first['last_seen_label'])
        ->toBe('2 days ago')
        ->and((string) $first['last_seen_label'])->not->toContain('.');
});

it('hides stale ai seo score values when source metrics are newer than the score snapshot', function () {
    [$user, $site, $analyticsSite] = createLearningsMetricsContext();

    createLearningsMetricEvent(
        $analyticsSite,
        'page_view',
        'https://example.com/articles/stale-ai-seo',
        '/articles/stale-ai-seo',
        'stale-ai-seo',
        now()->subMinutes(5)
    );

    DB::table('content_metrics')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => 'https://example.com/articles/stale-ai-seo',
        'url_key' => 'example.com/articles/stale-ai-seo',
        'roi_score' => 88.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_visibility')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => 'https://example.com/articles/stale-ai-seo',
        'url_key' => 'example.com/articles/stale-ai-seo',
        'llm_citations' => 2,
        'brand_mentions' => 5,
        'competitor_mentions' => 1,
        'ai_visibility_score' => 42.0,
        'last_checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content_ai_seo_scores')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'url' => 'https://example.com/articles/stale-ai-seo',
        'url_key' => 'example.com/articles/stale-ai-seo',
        'url_hash' => hash('sha256', (string) $analyticsSite->id . '|example.com/articles/stale-ai-seo'),
        'content_roi_score' => 80.0,
        'ai_visibility_score' => 30.0,
        'ai_visibility_score_normalized' => 30.0,
        'ai_seo_score' => 57.5,
        'weights_json' => json_encode(['content_roi' => 0.55, 'ai_visibility_normalized' => 0.45]),
        'formula_version' => 'ai_seo_v1',
        'calculated_at' => now()->subHour(),
        'content_metrics_updated_at' => now()->subHour(),
        'ai_visibility_updated_at' => now()->subHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $first = $response->viewData('trending')->first();

    expect($first['ai_seo_score'])->toBeNull()
        ->and($first['ai_seo_score_stale'])->toBeTrue();
});

function createLearningsMetricsContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Learnings Metrics Org',
        'slug' => 'learnings-metrics-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Metrics Org BV',
        'billing_address_line1' => 'Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Learnings Metrics Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'learnings-metrics-test-plan'],
        [
            'name' => 'Learnings Metrics Plan',
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
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Learnings Metrics Owner',
        'email' => 'learnings-metrics-owner-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Learnings Metrics Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);

    return [$user, $site, $analyticsSite];
}

function createLearningsMetricEvent(
    AnalyticsSite $analyticsSite,
    string $eventType,
    string $canonicalUrl,
    string $path,
    string $suffix,
    \Illuminate\Support\Carbon $eventTime
): void {
    AnalyticsEvent::query()->create([
        'analytics_site_id' => $analyticsSite->id,
        'event_type' => $eventType,
        'visitor_hash' => hash('sha256', "visitor-{$suffix}"),
        'session_hash' => hash('sha256', "session-{$suffix}"),
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'title' => 'Engagement Test Article',
        'host' => 'example.com',
        'url' => $canonicalUrl,
        'canonical_url' => $canonicalUrl,
        'canonical_url_hash' => hash('sha256', $canonicalUrl),
        'url_key' => 'example.com' . $path,
        'content_id' => null,
        'page_type' => 'other_page',
        'event_hash' => hash('sha256', "event-{$suffix}"),
        'event_time' => $eventTime,
        'received_at' => $eventTime,
    ]);
}

function forceOnlyFullGroupByForCurrentSession(): void
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
}
