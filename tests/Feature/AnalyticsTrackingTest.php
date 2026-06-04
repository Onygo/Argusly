<?php

namespace Tests\Feature;

use App\Models\AnalyticsSite;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('analytics.privacy.salt', 'testing-salt');
    }

    public function test_tracking_script_is_served_on_track_domain(): void
    {
        $this->get('http://track.argusly.local/argusly.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('Argusly Analytics', false)
            ->assertSee('window.Argusly.track', false);

        $this->get('http://track.argusly.local/pl.js')
            ->assertOk()
            ->assertSee('Argusly Analytics', false);
    }

    public function test_config_endpoint_exposes_tracking_settings_for_verified_site(): void
    {
        $site = $this->createVerifiedSite();

        $this->getJson('http://track.argusly.local/api/v1/config?site='.$site->public_key)
            ->assertOk()
            ->assertJson([
                'allowed' => true,
                'respectDnt' => true,
                'sampling' => 100,
            ]);
    }

    public function test_event_endpoint_stores_pageview_payload(): void
    {
        $site = $this->createVerifiedSite();

        $this->postJson('http://track.argusly.local/api/tracking/events', [
            'site_key' => $site->public_key,
            'event_type' => 'pageview',
            'url' => 'https://example.com/Blog/Intro?utm_source=test',
            'canonical_url' => 'https://example.com/blog/intro',
            'page_title' => 'Intro',
            'session_id' => 'session-1',
            'occurred_at' => '2026-06-03T10:00:00+00:00',
        ], [
            'Origin' => 'https://example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0',
        ])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => 1, 'received' => 1]);

        $this->assertDatabaseHas('analytics_events', [
            'analytics_site_id' => $site->id,
            'event_type' => 'page_view',
            'host' => 'example.com',
            'path' => '/blog/intro',
            'title' => 'Intro',
            'url_key' => 'example.com/blog/intro',
            'device_type' => 'desktop',
        ]);
    }

    public function test_event_endpoint_rejects_unapproved_origin(): void
    {
        $site = $this->createVerifiedSite();

        $this->postJson('http://track.argusly.local/api/tracking/events', [
            'site_key' => $site->public_key,
            'event_type' => 'pageview',
            'url' => 'https://example.com/',
        ], [
            'Origin' => 'https://evil.example',
        ])->assertForbidden();

        $this->assertDatabaseCount('analytics_events', 0);
    }

    public function test_event_endpoint_accepts_batch_payloads(): void
    {
        $site = $this->createVerifiedSite();

        $this->postJson('http://track.argusly.local/api/v1/events', [
            'site_key' => $site->public_key,
            'events' => [
                [
                    'event_type' => 'scroll_depth',
                    'url' => 'https://example.com/article',
                    'depth' => 75,
                    'session_id' => 'session-1',
                ],
                [
                    'event_type' => 'read_time',
                    'url' => 'https://example.com/article',
                    'seconds' => 34,
                    'session_id' => 'session-1',
                ],
            ],
        ], [
            'Origin' => 'https://www.example.com',
        ])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => 2, 'received' => 2]);

        $this->assertDatabaseHas('analytics_events', [
            'event_type' => 'scroll_depth',
            'path' => '/article',
        ]);
        $this->assertDatabaseHas('analytics_events', [
            'event_type' => 'read_time',
            'path' => '/article',
        ]);
    }

    private function createVerifiedSite(array $attributes = []): AnalyticsSite
    {
        $property = Property::factory()->create([
            'url' => 'https://example.com',
        ]);

        return AnalyticsSite::query()->create(array_merge([
            'property_id' => $property->id,
            'allowed_domains' => ['www.example.com'],
            'verified_at' => now(),
            'is_enabled' => true,
            'respect_dnt' => true,
            'sampling_rate' => 100,
        ], $attributes));
    }
}
