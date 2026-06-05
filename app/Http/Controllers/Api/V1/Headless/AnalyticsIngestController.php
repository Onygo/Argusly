<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Headless\IngestAnalyticsEventsRequest;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Services\Integrations\ApiCapabilityService;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\Integrations\DestinationResolverService;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Facades\DB;

class AnalyticsIngestController extends Controller
{
    use RespondsWithApi;

    private const EVENT_MAP = [
        'article_view' => 'page_view',
        'article_engaged' => 'engaged',
        'scroll_depth' => 'scroll_depth',
        'read_time' => 'read_time',
        'cta_click' => 'engaged',
        'start_signal' => 'heartbeat',
    ];

    public function store(
        IngestAnalyticsEventsRequest $request,
        ApiCapabilityService $capabilities,
        DestinationResolverService $destinationResolver,
        DestinationBillingSiteService $billingSiteService,
    ) {
        $workspace = $request->attributes->get('workspace');
        $apiKey = $request->attributes->get('apiKey');
        try {
            $capabilities->assertAnalyticsIngestEnabled($workspace);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), code: 'PLAN_LIMIT_REACHED', status: 422);
        }

        $validated = $request->validated();

        $destination = $destinationResolver->resolve(
            workspace: $workspace,
            apiKey: $apiKey,
            destinationId: $validated['content_destination_id'] ?? null,
        );
        $billingSite = $billingSiteService->ensureBillingSite($destination);

        $analyticsSite = AnalyticsSite::query()->firstOrCreate(
            ['client_site_id' => $billingSite->id],
            [
                'allowed_domains' => $billingSite->allowed_domains,
                'is_enabled' => true,
                'verified_at' => now(),
            ]
        );

        $salt = (string) config('analytics.privacy.salt', config('app.key', 'publishlayer'));

        $rows = [];
        foreach ($validated['events'] as $event) {
            $mappedType = self::EVENT_MAP[strtolower((string) $event['event_type'])] ?? null;
            if ($mappedType === null) {
                continue;
            }

            $pageUrl = (string) $event['page_url'];
            $urlParts = parse_url($pageUrl);
            $host = strtolower((string) ($urlParts['host'] ?? ''));
            $path = (string) ($urlParts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            }

            $eventTime = now()->parse((string) $event['timestamp']);
            $canonicalUrl = $this->normalizeUrl($pageUrl);
            $urlKey = AnalyticsUrlKey::fromUrl($canonicalUrl);
            $visitorSeed = trim((string) ($event['visitor_id'] ?? $request->ip() ?? 'anon'));
            $sessionSeed = trim((string) ($event['session_id'] ?? $visitorSeed));
            $articleIdentifier = trim((string) ($event['article_identifier'] ?? ''));

            $eventHash = hash('sha256', implode('|', [
                (string) $analyticsSite->id,
                $mappedType,
                $canonicalUrl,
                $eventTime->toIso8601String(),
                $sessionSeed,
                $visitorSeed,
            ]));

            $rows[] = [
                'analytics_site_id' => (string) $analyticsSite->id,
                'event_type' => $mappedType,
                'visitor_hash' => hash('sha256', $salt.'|v|'.$visitorSeed),
                'session_hash' => hash('sha256', $salt.'|s|'.$sessionSeed),
                'url' => $canonicalUrl,
                'canonical_url' => $canonicalUrl,
                'url_key' => $urlKey !== '' ? $urlKey : null,
                'canonical_url_hash' => hash('sha256', $canonicalUrl),
                'path' => $path,
                'path_hash' => AnalyticsEvent::computePathHash($path),
                'title' => null,
                'referrer' => null,
                'host' => $host,
                'article_id' => $articleIdentifier !== '' ? $articleIdentifier : null,
                'content_id' => null,
                'page_type' => 'publishlayer_content',
                'content_type' => null,
                'meta' => is_array($event['meta'] ?? null) ? $event['meta'] : null,
                'event_time' => $eventTime,
                'received_at' => now(),
                'event_hash' => $eventHash,
                'ip_hash' => hash('sha256', $salt.'|ip|'.(string) $request->ip()),
                'user_agent_family' => 'api',
                'device_type' => 'server',
                'created_at' => now(),
            ];
        }

        if ($rows === []) {
            return $this->error(
                'No valid analytics events were provided.',
                ['events' => ['Allowed event_type: '.implode(', ', array_keys(self::EVENT_MAP))]],
                'ANALYTICS_INVALID_EVENTS',
                422,
            );
        }

        $stored = DB::table('analytics_events')->insertOrIgnore($rows);

        return $this->success([
            'stored' => (int) $stored,
            'received' => count($rows),
            'analytics_site_id' => (string) $analyticsSite->id,
            'content_destination_id' => (string) $destination->id,
        ], status: 202);
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url(trim($url));
        $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $path = (string) ($parsed['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }

        $normalizedPath = $path === '/' ? '/' : rtrim($path, '/');

        return $scheme.'://'.$host.$normalizedPath;
    }
}
