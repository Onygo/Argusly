<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Stats\ContentMetricsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createAnalyticsTestSetup(): array
{
    $organization = Organization::query()->create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test.example.com',
        'allowed_domains' => ['test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['test.example.com'],
        'is_enabled' => true,
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'analyticsSite' => $analyticsSite,
    ];
}

beforeEach(function () {
    // Ensure content_metrics table exists for tests
    if (! Schema::hasTable('content_metrics')) {
        Schema::create('content_metrics', function ($table) {
            $table->id();
            $table->string('analytics_site_id');
            $table->string('url');
            $table->string('url_key')->index();
            $table->float('avg_scroll_depth')->default(0);
            $table->integer('max_scroll_depth')->default(0);
            $table->float('avg_read_time')->default(0);
            $table->float('median_read_time')->default(0);
            $table->float('engaged_rate')->default(0);
            $table->float('read_through_rate')->default(0);
            $table->float('estimated_read_time')->default(0);
            $table->float('roi_score')->default(0);
            $table->timestamps();

            $table->unique(['analytics_site_id', 'url_key']);
        });
    }

    // Ensure page_scroll_events table exists
    if (! Schema::hasTable('page_scroll_events')) {
        Schema::create('page_scroll_events', function ($table) {
            $table->id();
            $table->string('analytics_site_id');
            $table->string('url_key');
            $table->string('session_id');
            $table->string('url')->nullable();
            $table->integer('depth')->default(0);
            $table->timestamps();
        });
    }

    // Ensure page_read_sessions table exists
    if (! Schema::hasTable('page_read_sessions')) {
        Schema::create('page_read_sessions', function ($table) {
            $table->id();
            $table->string('analytics_site_id');
            $table->string('url_key');
            $table->string('url')->nullable();
            $table->integer('read_seconds')->default(0);
            $table->timestamps();
        });
    }
});

function createAnalyticsEvent(string $analyticsSiteId, array $overrides = []): AnalyticsEvent
{
    $path = $overrides['path'] ?? '/test-path';

    return AnalyticsEvent::query()->create(array_merge([
        'analytics_site_id' => $analyticsSiteId,
        'event_type' => 'page_view',
        'visitor_hash' => Str::random(64),
        'session_hash' => Str::random(64),
        'path' => $path,
        'path_hash' => AnalyticsEvent::computePathHash($path),
        'host' => 'test.example.com',
        'event_time' => now(),
    ], $overrides));
}

describe('ContentMetricsCalculator', function () {
    it('recalculates metrics with url_key column values', function () {
        $setup = createAnalyticsTestSetup();
        $analyticsSiteId = $setup['analyticsSite']->id;

        // Create pageview events with url_key set (simulates real data)
        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => 'test.example.com/blog/post-1',
            'url' => 'https://test.example.com/blog/post-1',
            'path' => '/blog/post-1',
        ]);

        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => 'test.example.com/blog/post-1',
            'url' => 'https://test.example.com/blog/post-1',
            'path' => '/blog/post-1',
        ]);

        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => 'test.example.com/blog/post-2',
            'url' => 'https://test.example.com/blog/post-2',
            'path' => '/blog/post-2',
        ]);

        $calculator = app(ContentMetricsCalculator::class);

        // This should NOT throw GROUP BY error under MySQL strict mode
        $upsertCount = $calculator->recalculate($analyticsSiteId);

        expect($upsertCount)->toBe(2);

        $metrics = DB::table('content_metrics')
            ->where('analytics_site_id', $analyticsSiteId)
            ->get();

        expect($metrics)->toHaveCount(2);
        expect($metrics->pluck('url_key')->sort()->values()->all())->toBe([
            'test.example.com/blog/post-1',
            'test.example.com/blog/post-2',
        ]);
    });

    it('handles events without url_key by falling back to host+path', function () {
        $setup = createAnalyticsTestSetup();
        $analyticsSiteId = $setup['analyticsSite']->id;

        // Create events WITHOUT url_key (will use host+path fallback)
        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => null,
            'url' => 'https://test.example.com/about',
            'path' => '/about',
        ]);

        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => '',
            'url' => 'https://test.example.com/about',
            'path' => '/about',
        ]);

        $calculator = app(ContentMetricsCalculator::class);

        // Should work without GROUP BY error
        $upsertCount = $calculator->recalculate($analyticsSiteId);

        expect($upsertCount)->toBe(1);

        $metric = DB::table('content_metrics')
            ->where('analytics_site_id', $analyticsSiteId)
            ->first();

        expect($metric)->not->toBeNull();
        expect($metric->url_key)->toBe('test.example.com/about');
    });

    it('aggregates engaged events correctly with url_key grouping', function () {
        $setup = createAnalyticsTestSetup();
        $analyticsSiteId = $setup['analyticsSite']->id;

        // Create pageview events
        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => 'test.example.com/article',
            'url' => 'https://test.example.com/article',
            'path' => '/article',
        ]);

        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'page_view',
            'url_key' => 'test.example.com/article',
            'url' => 'https://test.example.com/article',
            'path' => '/article',
        ]);

        // Create engaged event
        createAnalyticsEvent($analyticsSiteId, [
            'event_type' => 'engaged',
            'url_key' => 'test.example.com/article',
            'url' => 'https://test.example.com/article',
            'path' => '/article',
        ]);

        $calculator = app(ContentMetricsCalculator::class);
        $calculator->recalculate($analyticsSiteId);

        $metric = DB::table('content_metrics')
            ->where('analytics_site_id', $analyticsSiteId)
            ->where('url_key', 'test.example.com/article')
            ->first();

        expect($metric)->not->toBeNull();
        // engaged_rate = 1 engaged / 2 pageviews = 0.5
        expect((float) $metric->engaged_rate)->toBe(0.5);
    });
});
