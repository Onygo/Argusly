<?php

use App\Jobs\Analytics\BuildAnalyticsRollupsJob;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsRollupDaily;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['analytics.enabled' => true]);
    config(['analytics.rollup.lookback_days' => 2]);
});

function createAnalyticsSiteForRollup(): AnalyticsSite
{
    $organization = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $clientSite = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    return AnalyticsSite::create([
        'client_site_id' => $clientSite->id,
        'allowed_domains' => ['example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);
}

function createEvent(AnalyticsSite $site, string $type, string $path, Carbon $time, string $visitorHash = null, string $sessionHash = null): void
{
    $visitorHash = $visitorHash ?? hash('sha256', 'visitor-'.Str::random(8));
    $sessionHash = $sessionHash ?? hash('sha256', 'session-'.Str::random(8));

    AnalyticsEvent::create([
        'analytics_site_id' => $site->id,
        'event_type' => $type,
        'visitor_hash' => $visitorHash,
        'session_hash' => $sessionHash,
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'title' => "Page: {$path}",
        'host' => 'example.com',
        'event_time' => $time,
    ]);
}

describe('Analytics Rollup Job', function () {
    it('aggregates page views correctly', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        // Create 5 page views for /
        for ($i = 0; $i < 5; $i++) {
            createEvent($site, 'page_view', '/', $today->copy()->addHours($i));
        }

        // Create 3 page views for /about
        for ($i = 0; $i < 3; $i++) {
            createEvent($site, 'page_view', '/about', $today->copy()->addHours($i));
        }

        // Run rollup job
        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollups = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->get();

        expect($rollups)->toHaveCount(2);

        $homeRollup = $rollups->firstWhere('path', '/');
        expect($homeRollup->page_views)->toBe(5);
        expect($homeRollup->date->toDateString())->toBe($today->toDateString());

        $aboutRollup = $rollups->firstWhere('path', '/about');
        expect($aboutRollup->page_views)->toBe(3);
    });

    it('calculates unique visitors correctly', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        $visitor1 = hash('sha256', 'visitor-1');
        $visitor2 = hash('sha256', 'visitor-2');

        // Visitor 1 views page 3 times
        for ($i = 0; $i < 3; $i++) {
            createEvent($site, 'page_view', '/', $today->copy()->addHours($i), $visitor1);
        }

        // Visitor 2 views page 2 times
        for ($i = 0; $i < 2; $i++) {
            createEvent($site, 'page_view', '/', $today->copy()->addHours($i), $visitor2);
        }

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();

        expect($rollup->page_views)->toBe(5);
        expect($rollup->unique_visitors)->toBe(2);
    });

    it('aggregates scroll events correctly', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        createEvent($site, 'page_view', '/', $today);
        createEvent($site, 'scroll_50', '/', $today->copy()->addMinutes(1));
        createEvent($site, 'scroll_100', '/', $today->copy()->addMinutes(2));

        createEvent($site, 'page_view', '/', $today->copy()->addHours(1));
        createEvent($site, 'scroll_50', '/', $today->copy()->addHours(1)->addMinutes(1));
        // No scroll_100 for this session

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();

        expect($rollup->page_views)->toBe(2);
        expect($rollup->scroll_50)->toBe(2);
        expect($rollup->scroll_100)->toBe(1);
    });

    it('calculates engaged views correctly', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        $session1 = hash('sha256', 'session-1');
        $session2 = hash('sha256', 'session-2');
        $session3 = hash('sha256', 'session-3');
        $visitor1 = hash('sha256', 'visitor-1');

        // Session 1: page_view + scroll = engaged
        createEvent($site, 'page_view', '/', $today, $visitor1, $session1);
        createEvent($site, 'scroll_50', '/', $today->copy()->addMinutes(1), $visitor1, $session1);

        // Session 2: page_view + heartbeat = engaged
        createEvent($site, 'page_view', '/', $today->copy()->addHours(1), $visitor1, $session2);
        createEvent($site, 'heartbeat', '/', $today->copy()->addHours(1)->addMinutes(1), $visitor1, $session2);

        // Session 3: page_view only = not engaged
        createEvent($site, 'page_view', '/', $today->copy()->addHours(2), $visitor1, $session3);

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();

        expect($rollup->page_views)->toBe(3);
        expect($rollup->engaged_views)->toBe(2);
    });

    it('calculates total time from heartbeats', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        createEvent($site, 'page_view', '/', $today);
        // 4 heartbeats = 4 * 15 seconds = 60 seconds
        for ($i = 0; $i < 4; $i++) {
            createEvent($site, 'heartbeat', '/', $today->copy()->addSeconds(15 * ($i + 1)));
        }

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();

        expect($rollup->heartbeats)->toBe(4);
        expect($rollup->total_time_seconds)->toBe(60); // 4 heartbeats * 15 seconds
    });

    it('stores title from events', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        AnalyticsEvent::create([
            'analytics_site_id' => $site->id,
            'event_type' => 'page_view',
            'visitor_hash' => hash('sha256', 'visitor'),
            'session_hash' => hash('sha256', 'session'),
            'path' => '/blog/my-article',
            'path_hash' => hash('sha256', '/blog/my-article'),
            'title' => 'My Amazing Article',
            'host' => 'example.com',
            'event_time' => $today,
        ]);

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();

        expect($rollup->title)->toBe('My Amazing Article');
    });

    it('upserts existing rollups', function () {
        $site = createAnalyticsSiteForRollup();
        $today = now()->startOfDay();

        // Create initial event
        createEvent($site, 'page_view', '/', $today);

        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup = AnalyticsRollupDaily::where('analytics_site_id', $site->id)->first();
        expect($rollup->page_views)->toBe(1);

        // Add more events
        createEvent($site, 'page_view', '/', $today->copy()->addHours(1));
        createEvent($site, 'page_view', '/', $today->copy()->addHours(2));

        // Run rollup again
        BuildAnalyticsRollupsJob::dispatchSync($site->id, $today);

        $rollup->refresh();
        expect($rollup->page_views)->toBe(3);

        // Should still be only 1 rollup record
        expect(AnalyticsRollupDaily::where('analytics_site_id', $site->id)->count())->toBe(1);
    });
});
