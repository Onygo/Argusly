<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['domains.base' => 'argusly.local']);
    config(['analytics.enabled' => true]);
    config(['analytics.privacy.salt' => 'test-salt']);
});

function createAnalyticsSite(bool $verified = true, bool $enabled = true, array $allowedDomains = []): array
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
        'allowed_domains' => $allowedDomains ?: ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::create([
        'client_site_id' => $clientSite->id,
        'allowed_domains' => $allowedDomains ?: ['example.com'],
        'is_enabled' => $enabled,
        'verified_at' => $verified ? now() : null,
    ]);

    return [$analyticsSite, $clientSite];
}

function makeTrackRequest(object $testCase, string $method, string $path, array $data = [], array $headers = [])
{
    $baseDomain = config('domains.base', 'argusly.local');
    $host = "track.{$baseDomain}";
    $url = "http://{$host}{$path}";

    $defaultHeaders = ['Host' => $host, 'Origin' => 'https://example.com'];
    $headers = array_merge($defaultHeaders, $headers);

    $request = $testCase->withHeaders($headers);

    if ($method === 'GET') {
        return $request->get($url);
    }

    return $request->postJson($url, $data);
}

describe('Analytics Event Ingestion', function () {
    it('rejects events for non-existent site', function () {
        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => 'pl_nonexistent123456789012',
            'events' => [['type' => 'page_view', 'host' => 'example.com', 'path' => '/']],
        ]);

        $response->assertStatus(404);
        expect($response->json('error'))->toBe('Site not found');
    });

    it('rejects events for unverified site', function () {
        [$analyticsSite] = createAnalyticsSite(verified: false);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [['type' => 'page_view', 'host' => 'example.com', 'path' => '/']],
        ]);

        $response->assertStatus(403);
        expect($response->json('error'))->toBe('Site not verified');
    });

    it('rejects events for disabled site', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, enabled: false);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [['type' => 'page_view', 'host' => 'example.com', 'path' => '/']],
        ]);

        $response->assertStatus(403);
        expect($response->json('error'))->toBe('Site disabled');
    });

    it('rejects events with host not in allowed domains', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [['type' => 'page_view', 'host' => 'malicious.com', 'path' => '/']],
        ], ['Origin' => 'https://malicious.com']);

        $response->assertStatus(403);
        expect($response->json('error'))->toBe('Origin not allowed');
        expect(AnalyticsEvent::count())->toBe(0);
    });

    it('stores valid events for verified site', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [
                ['type' => 'page_view', 'host' => 'example.com', 'path' => '/', 'title' => 'Home'],
                ['type' => 'scroll_50', 'host' => 'example.com', 'path' => '/'],
                ['type' => 'scroll_100', 'host' => 'example.com', 'path' => '/'],
            ],
        ]);

        $response->assertOk();
        expect($response->json('ok'))->toBeTrue();
        expect($response->json('stored'))->toBe(3);
        expect(AnalyticsEvent::count())->toBe(3);

        $pageView = AnalyticsEvent::where('event_type', 'page_view')->first();
        expect($pageView->analytics_site_id)->toBe($analyticsSite->id);
        expect($pageView->path)->toBe('/');
        expect($pageView->title)->toBe('Home');
        expect($pageView->host)->toBe('example.com');
    });

    it('rejects batch exceeding max events', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true);

        $events = array_fill(0, 51, [
            'type' => 'page_view',
            'host' => 'example.com',
            'path' => '/',
        ]);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => $events,
        ]);

        $response->assertStatus(400);
        expect($response->json('error'))->toContain('Max 50 events');
    });

    it('filters invalid event types', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true);

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [
                ['type' => 'page_view', 'host' => 'example.com', 'path' => '/'],
                ['type' => 'invalid_type', 'host' => 'example.com', 'path' => '/'],
                ['type' => 'scroll_50', 'host' => 'example.com', 'path' => '/'],
            ],
        ]);

        $response->assertOk();
        expect($response->json('stored'))->toBe(2);
    });

    it('stores article context when provided', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true);

        $articleId = (string) Str::uuid();

        $response = makeTrackRequest($this, 'POST', '/api/v1/events', [
            'site' => $analyticsSite->public_key,
            'events' => [
                [
                    'type' => 'page_view',
                    'host' => 'example.com',
                    'path' => '/blog/post-1',
                    'articleId' => $articleId,
                    'contentType' => 'article',
                ],
            ],
        ]);

        $response->assertOk();
        $event = AnalyticsEvent::first();
        expect($event->article_id)->toBe($articleId);
        expect($event->content_type)->toBe('article');
    });
});

describe('Tracking Events Endpoint', function () {
    it('accepts read_through with valid site_key and allowed origin', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'read_through',
            'url' => 'https://example.com/blog/read-through-post?src=home',
            'canonical_url' => 'https://example.com/blog/read-through-post',
            'occurred_at' => now()->toIso8601String(),
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect($response->json('ok'))->toBeTrue();
        expect($response->json('stored'))->toBe(1);
        expect(AnalyticsEvent::query()->count())->toBe(1);
        expect(AnalyticsEvent::query()->first()?->event_type)->toBe('read_through');
    });

    it('accepts pageview, engaged, and read_through for valid site_key and allowed origin', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'events' => [
                [
                    'event_type' => 'pageview',
                    'url' => 'https://example.com/blog/launch-post?src=home',
                    'canonical_url' => 'https://example.com/blog/launch-post',
                    'referrer' => 'https://google.com',
                    'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'event_type' => 'engaged',
                    'url' => 'https://example.com/blog/launch-post?src=home',
                    'canonical_url' => 'https://example.com/blog/launch-post',
                    'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'event_type' => 'read_through',
                    'url' => 'https://example.com/blog/launch-post?src=home',
                    'canonical_url' => 'https://example.com/blog/launch-post',
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect($response->json('ok'))->toBeTrue();
        expect($response->json('stored'))->toBe(3);
        expect(AnalyticsEvent::count())->toBe(3);
        expect(AnalyticsEvent::query()->where('event_type', 'page_view')->exists())->toBeTrue();
        expect(AnalyticsEvent::query()->where('event_type', 'engaged')->exists())->toBeTrue();
        expect(AnalyticsEvent::query()->where('event_type', 'read_through')->exists())->toBeTrue();
    });

    it('normalizes tracked URLs and prefers canonical URL for storage', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'pageview',
            'url' => 'https://EXAMPLE.com/Article/?utm=campaign#section',
            'canonical_url' => 'https://example.com/article/',
            'occurred_at' => now()->toIso8601String(),
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect($response->json('stored'))->toBe(1);

        $event = AnalyticsEvent::query()->first();
        expect($event)->not->toBeNull();
        expect((string) $event->url)->toBe('https://example.com/article');
        expect((string) $event->canonical_url)->toBe('https://example.com/article');
        expect((string) $event->url_key)->toBe('example.com/article');
        expect((string) $event->path)->toBe('/article');
        expect((string) $event->host)->toBe('example.com');
    });

    it('rejects invalid site_key', function () {
        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => 'pl_invalid_key',
            'event_type' => 'pageview',
            'url' => 'https://example.com/blog/post',
            'canonical_url' => 'https://example.com/blog/post',
        ]);

        $response->assertStatus(404);
        expect($response->json('error'))->toBe('Site not found');
    });

    it('rejects disallowed origin', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'pageview',
            'url' => 'https://example.com/blog/post',
            'canonical_url' => 'https://example.com/blog/post',
        ], ['Origin' => 'https://malicious.com']);

        $response->assertStatus(403);
        expect($response->json('error'))->toBe('Origin not allowed');
        expect(AnalyticsEvent::count())->toBe(0);
    });

    it('rejects unknown event_type readthrough', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'readthrough',
            'url' => 'https://example.com/blog/post',
            'canonical_url' => 'https://example.com/blog/post',
            'occurred_at' => '2026-03-03T12:00:10Z',
        ], ['Origin' => 'https://example.com']);

        $response->assertStatus(422);
        expect($response->json('error'))->toBe('Invalid event_type');
        expect(AnalyticsEvent::count())->toBe(0);
    });

    it('dedupe prevents duplicate read_through in same time bucket', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $payload = [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'read_through',
            'url' => 'https://example.com/blog/post',
            'canonical_url' => 'https://example.com/blog/post',
            'occurred_at' => '2026-03-03T12:00:10Z',
        ];

        $first = makeTrackRequest($this, 'POST', '/api/tracking/events', $payload, ['Origin' => 'https://example.com']);
        $second = makeTrackRequest($this, 'POST', '/api/tracking/events', $payload, ['Origin' => 'https://example.com']);

        $first->assertOk();
        $second->assertOk();
        expect($first->json('stored'))->toBe(1);
        expect($second->json('stored'))->toBe(0);
        expect(AnalyticsEvent::query()->where('event_type', 'read_through')->count())->toBe(1);
    });

    it('dedupe works per event_type in the same time bucket', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        foreach (['pageview', 'engaged', 'read_through'] as $eventType) {
            $payload = [
                'site_key' => $analyticsSite->public_key,
                'event_type' => $eventType,
                'url' => 'https://example.com/blog/post',
                'canonical_url' => 'https://example.com/blog/post',
                'occurred_at' => '2026-03-03T12:00:10Z',
            ];

            $first = makeTrackRequest($this, 'POST', '/api/tracking/events', $payload, ['Origin' => 'https://example.com']);
            $second = makeTrackRequest($this, 'POST', '/api/tracking/events', $payload, ['Origin' => 'https://example.com']);

            $first->assertOk();
            $second->assertOk();
            expect($first->json('stored'))->toBe(1);
            expect($second->json('stored'))->toBe(0);
        }

        expect(AnalyticsEvent::query()->whereIn('event_type', ['page_view', 'engaged', 'read_through'])->count())->toBe(3);
    });

    it('classifies page views into publishlayer content or other pages', function () {
        [$analyticsSite, $clientSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $content = Content::query()->create([
            'workspace_id' => $clientSite->workspace_id,
            'client_site_id' => $clientSite->id,
            'title' => 'Launch Post',
            'published_url' => 'https://example.com/blog/launch-post',
        ]);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'events' => [
                [
                    'event_type' => 'pageview',
                    'url' => 'https://example.com/blog/launch-post?src=nav',
                    'canonical_url' => 'https://example.com/blog/launch-post',
                    'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'event_type' => 'pageview',
                    'url' => 'https://example.com/pricing?src=nav',
                    'canonical_url' => 'https://example.com/pricing',
                    'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'event_type' => 'engaged',
                    'url' => 'https://example.com/blog/launch-post?src=nav',
                    'canonical_url' => 'https://example.com/blog/launch-post',
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect($response->json('stored'))->toBe(3);

        $mapped = AnalyticsEvent::query()->where('canonical_url', 'https://example.com/blog/launch-post')->first();
        $other = AnalyticsEvent::query()->where('canonical_url', 'https://example.com/pricing')->first();
        $engaged = AnalyticsEvent::query()
            ->where('canonical_url', 'https://example.com/blog/launch-post')
            ->where('event_type', 'engaged')
            ->first();

        expect($mapped)->not->toBeNull();
        expect((string) $mapped->url_key)->toBe('example.com/blog/launch-post');
        expect((string) $mapped->content_id)->toBe((string) $content->id);
        expect((string) $mapped->page_type)->toBe('publishlayer_content');

        expect($other)->not->toBeNull();
        expect((string) $other->url_key)->toBe('example.com/pricing');
        expect($other->content_id)->toBeNull();
        expect((string) $other->page_type)->toBe('other_page');

        expect($engaged)->not->toBeNull();
        expect((string) $engaged->content_id)->toBe((string) $content->id);
        expect((string) $engaged->page_type)->toBe('publishlayer_content');
    });

    it('marks page as publishlayer content when article_id is present without URL mapping', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);
        $articleId = (string) Str::uuid();

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event_type' => 'pageview',
            'url' => 'https://example.com/custom/landing?utm=abc',
            'article_id' => $articleId,
            'occurred_at' => now()->toIso8601String(),
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect($response->json('stored'))->toBe(1);

        $event = AnalyticsEvent::query()->first();
        expect($event)->not->toBeNull();
        expect((string) $event->article_id)->toBe($articleId);
        expect($event->content_id)->toBeNull();
        expect((string) $event->page_type)->toBe('publishlayer_content');
    });

    it('stores scroll depth milestones per session', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'events' => [
                [
                    'event' => 'scroll_depth',
                    'url' => 'https://example.com/article?utm=123',
                    'depth' => 25,
                    'session_id' => 'session-alpha',
                    'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'event' => 'scroll_depth',
                    'url' => 'https://example.com/article?utm=456',
                    'depth' => 75,
                    'session_id' => 'session-alpha',
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        expect((int) DB::table('page_scroll_events')->count())->toBe(2);
        expect(DB::table('page_scroll_events')->where('depth', 25)->exists())->toBeTrue();
        expect(DB::table('page_scroll_events')->where('depth', 75)->exists())->toBeTrue();
    });

    it('stores read time sessions keyed by session id', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true, allowedDomains: ['example.com']);

        $response = makeTrackRequest($this, 'POST', '/api/tracking/events', [
            'site_key' => $analyticsSite->public_key,
            'event' => 'read_time',
            'url' => 'https://example.com/article?utm=789',
            'seconds' => 42,
            'session_id' => 'session-read-1',
            'occurred_at' => now()->toIso8601String(),
        ], ['Origin' => 'https://example.com']);

        $response->assertOk();
        $session = DB::table('page_read_sessions')->first();
        expect($session)->not->toBeNull();
        expect((int) $session->read_seconds)->toBe(42);
        expect((string) $session->session_id)->toBe('session-read-1');
    });
});

describe('Analytics Config Endpoint', function () {
    it('returns allowed=true for verified site', function () {
        [$analyticsSite] = createAnalyticsSite(verified: true);

        $response = makeTrackRequest($this, 'GET', '/api/v1/config?site='.$analyticsSite->public_key);

        $response->assertOk();
        expect($response->json('allowed'))->toBeTrue();
        expect($response->json('respectDnt'))->toBeTrue();
        expect($response->json('sampling'))->toBe(100);
    });

    it('returns allowed=false for unverified site', function () {
        [$analyticsSite] = createAnalyticsSite(verified: false);

        $response = makeTrackRequest($this, 'GET', '/api/v1/config?site='.$analyticsSite->public_key);

        $response->assertOk();
        expect($response->json('allowed'))->toBeFalse();
        expect($response->json('reason'))->toBe('unverified');
    });

    it('returns 404 for non-existent site', function () {
        $response = makeTrackRequest($this, 'GET', '/api/v1/config?site=pl_invalid');

        $response->assertStatus(404);
    });
});
