<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recalculates ROI and advanced metrics for tracked pages', function () {
    $organization = Organization::query()->create([
        'name' => 'Metrics Command Org',
        'slug' => 'metrics-command-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Metrics Command Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Metrics Command Site',
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

    $url = 'https://example.com/article';
    $urlKey = 'example.com/article';

    for ($i = 0; $i < 5; $i++) {
        AnalyticsEvent::query()->create([
            'analytics_site_id' => $analyticsSite->id,
            'event_type' => 'page_view',
            'visitor_hash' => hash('sha256', 'v-' . $i),
            'session_hash' => hash('sha256', 's-' . $i),
            'path' => '/article',
            'path_hash' => hash('sha256', '/article'),
            'title' => 'Article',
            'host' => 'example.com',
            'url' => $url,
            'canonical_url' => $url,
            'canonical_url_hash' => hash('sha256', $url),
            'url_key' => $urlKey,
            'content_id' => null,
            'page_type' => 'other_page',
            'event_hash' => hash('sha256', 'pv-' . $i),
            'event_time' => now()->subMinutes(10 - $i),
            'received_at' => now(),
        ]);
    }

    for ($i = 0; $i < 3; $i++) {
        AnalyticsEvent::query()->create([
            'analytics_site_id' => $analyticsSite->id,
            'event_type' => 'engaged',
            'visitor_hash' => hash('sha256', 'ev-' . $i),
            'session_hash' => hash('sha256', 'es-' . $i),
            'path' => '/article',
            'path_hash' => hash('sha256', '/article'),
            'title' => 'Article',
            'host' => 'example.com',
            'url' => $url,
            'canonical_url' => $url,
            'canonical_url_hash' => hash('sha256', $url),
            'url_key' => $urlKey,
            'content_id' => null,
            'page_type' => 'other_page',
            'event_hash' => hash('sha256', 'eng-' . $i),
            'event_time' => now()->subMinutes(8 - $i),
            'received_at' => now(),
        ]);
    }

    DB::table('page_scroll_events')->insert([
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => $url,
            'url_key' => $urlKey,
            'session_id' => 'session-1',
            'depth' => 25,
            'created_at' => now(),
        ],
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => $url,
            'url_key' => $urlKey,
            'session_id' => 'session-1',
            'depth' => 50,
            'created_at' => now(),
        ],
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => $url,
            'url_key' => $urlKey,
            'session_id' => 'session-2',
            'depth' => 100,
            'created_at' => now(),
        ],
    ]);

    DB::table('page_read_sessions')->insert([
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => $url,
            'url_key' => $urlKey,
            'session_id' => 'session-1',
            'read_seconds' => 45,
            'created_at' => now(),
        ],
        [
            'analytics_site_id' => $analyticsSite->id,
            'url' => $url,
            'url_key' => $urlKey,
            'session_id' => 'session-2',
            'read_seconds' => 75,
            'created_at' => now(),
        ],
    ]);

    Artisan::call('stats:recalculate-content-metrics', ['--sync' => true]);

    $row = DB::table('content_metrics')
        ->where('analytics_site_id', $analyticsSite->id)
        ->where('url_key', $urlKey)
        ->first();

    expect($row)->not->toBeNull();
    expect((float) $row->avg_scroll_depth)->toBe(75.0);
    expect((float) $row->avg_read_time)->toBe(60.0);
    expect((float) $row->roi_score)->toBeGreaterThan(0.0);
});
