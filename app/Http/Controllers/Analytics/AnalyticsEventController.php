<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSite;
use App\Support\Analytics\AnalyticsUrlKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsEventController extends Controller
{
    private const ALLOWED_EVENT_TYPES = [
        'page_view',
        'pageview',
        'engaged',
        'read_through',
        'scroll_50',
        'scroll_100',
        'scroll_depth',
        'read_time',
        'heartbeat',
    ];

    public function store(Request $request): JsonResponse
    {
        if (! config('analytics.enabled', true)) {
            return response()->json(['error' => 'Analytics disabled'], 404);
        }

        $payload = $this->extractPayload($request);
        $publicKey = trim((string) ($payload['site_key'] ?? $payload['site'] ?? ''));

        if ($publicKey === '') {
            return response()->json(['error' => 'Missing site_key'], 400);
        }

        $site = $request->attributes->get('analytics.site');
        if (! $site instanceof AnalyticsSite || $site->public_key !== $publicKey) {
            $site = AnalyticsSite::query()
                ->with('property:id,url')
                ->where('public_key', $publicKey)
                ->first();
        }

        if (! $site) {
            return response()->json(['error' => 'Site not found'], 404);
        }

        if (! $site->is_enabled) {
            return response()->json(['error' => 'Site disabled'], 403);
        }

        if (! $site->verified_at) {
            return response()->json(['error' => 'Site not verified'], 403);
        }

        $rawPayload = (string) $request->getContent();
        $maxPayloadBytes = (int) config('analytics.ingestion.max_payload_bytes', 32768);
        if ($maxPayloadBytes > 0 && strlen($rawPayload) > $maxPayloadBytes) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        $events = $this->normalizeIncomingEvents($payload);
        $maxEvents = (int) config('analytics.ingestion.max_events_per_batch', 50);
        if (count($events) > $maxEvents) {
            return response()->json(['error' => "Max {$maxEvents} events per batch"], 400);
        }

        if ($events === []) {
            return response()->json(['ok' => true, 'stored' => 0]);
        }

        $allowedDomains = $request->attributes->get('analytics.allowed_domains');
        if (! is_array($allowedDomains)) {
            $allowedDomains = $this->normalizeAllowedDomains($site);
        }

        if (! $this->requestOriginAllowed($request, $allowedDomains)) {
            return response()->json(['error' => 'Origin not allowed'], 403);
        }

        $rows = [];
        $invalidEventTypes = [];
        $receivedAt = now();
        $maxStringLength = (int) config('analytics.ingestion.max_string_length', 2000);

        foreach ($events as $event) {
            $eventType = $this->normalizeEventType((string) ($event['event_type'] ?? $event['type'] ?? $event['event'] ?? ''));

            if ($eventType === null) {
                $invalidEventTypes[] = strtolower(trim((string) ($event['event_type'] ?? $event['type'] ?? $event['event'] ?? '')));

                continue;
            }

            $row = $this->normalizeEventRow(
                event: $event,
                eventType: $eventType,
                request: $request,
                site: $site,
                allowedDomains: $allowedDomains,
                maxStringLength: $maxStringLength,
                receivedAt: $receivedAt,
                fallbackReferrer: (string) $request->header('Referer', '')
            );

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            if ($invalidEventTypes !== []) {
                return response()->json([
                    'error' => 'Invalid event_type',
                    'allowed_event_types' => self::ALLOWED_EVENT_TYPES,
                ], 422);
            }

            return response()->json(['ok' => true, 'stored' => 0]);
        }

        $stored = DB::table('analytics_events')->insertOrIgnore($rows);

        return response()->json([
            'ok' => true,
            'stored' => $stored,
            'received' => count($rows),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(Request $request): array
    {
        $payload = $request->all();
        if (is_array($payload) && ($payload !== [] || $request->isJson())) {
            return $payload;
        }

        $decoded = json_decode(trim((string) $request->getContent()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function normalizeIncomingEvents(array $payload): array
    {
        $events = $payload['events'] ?? null;

        if (is_array($events)) {
            if (array_is_list($events)) {
                return array_values(array_filter($events, fn ($event) => is_array($event)));
            }

            if (isset($events['event_type']) || isset($events['type']) || isset($events['event'])) {
                return [$events];
            }

            return [];
        }

        if (isset($payload['event_type']) || isset($payload['type']) || isset($payload['event'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<int, string>  $allowedDomains
     * @return array<string, mixed>|null
     */
    private function normalizeEventRow(
        array $event,
        string $eventType,
        Request $request,
        AnalyticsSite $site,
        array $allowedDomains,
        int $maxStringLength,
        Carbon $receivedAt,
        string $fallbackReferrer
    ): ?array {
        $url = AnalyticsUrlKey::normalizeUrl((string) ($event['url'] ?? ''));
        $canonicalUrl = AnalyticsUrlKey::normalizeUrl((string) ($event['canonical_url'] ?? $event['canonicalUrl'] ?? ''));

        if ($canonicalUrl !== null) {
            $url = $canonicalUrl;
        }

        $canonicalUrl ??= $url;
        if ($canonicalUrl === null) {
            return null;
        }

        $eventHost = $this->extractHost($canonicalUrl);
        if ($eventHost === '' || ! $this->isHostAllowed($eventHost, $allowedDomains)) {
            return null;
        }

        $path = Str::limit(AnalyticsUrlKey::pathFromUrl($canonicalUrl) ?: '/', $maxStringLength, '');
        $occurredAt = $this->resolveOccurredAt($event);
        $canonicalUrlHash = hash('sha256', $canonicalUrl);
        $eventHash = hash('sha256', implode('|', [
            $site->id,
            $canonicalUrlHash,
            $eventType,
            (string) intdiv($occurredAt->timestamp, 30),
            $this->resolveEventDiscriminator($eventType, $event),
        ]));

        $meta = isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : [];
        $sessionId = trim((string) ($event['session_id'] ?? $event['sessionId'] ?? data_get($meta, 'session_id', '')));
        if ($sessionId !== '') {
            $meta['session_id'] = Str::limit($sessionId, 128, '');
        }

        if ($eventType === 'scroll_depth') {
            $meta['depth'] = max(0, min(100, (int) ($event['depth'] ?? data_get($meta, 'depth', 0))));
        }

        if ($eventType === 'read_time') {
            $meta['read_seconds'] = max(0, (int) ($event['seconds'] ?? $event['read_seconds'] ?? data_get($meta, 'read_seconds', 0)));
        }

        $visitorHash = $this->computeVisitorHash($request, $occurredAt);

        return [
            'analytics_site_id' => $site->id,
            'event_type' => $eventType,
            'visitor_hash' => $visitorHash,
            'session_hash' => $this->computeSessionHash($visitorHash, $occurredAt),
            'url' => Str::limit($url ?? $canonicalUrl, $maxStringLength, ''),
            'canonical_url' => Str::limit($canonicalUrl, $maxStringLength, ''),
            'url_key' => Str::limit(AnalyticsUrlKey::fromUrl($canonicalUrl), 512, '') ?: null,
            'canonical_url_hash' => $canonicalUrlHash,
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'title' => Str::limit(trim((string) ($event['title'] ?? $event['page_title'] ?? '')), 500, '') ?: null,
            'referrer' => Str::limit((string) ($event['referrer'] ?? $fallbackReferrer), $maxStringLength, '') ?: null,
            'host' => $eventHost,
            'article_id' => Str::limit(trim((string) ($event['article_id'] ?? $event['articleId'] ?? '')), 128, '') ?: null,
            'content_type' => Str::limit(trim((string) ($event['content_type'] ?? $event['contentType'] ?? '')), 64, '') ?: null,
            'meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'event_time' => $occurredAt->toDateTimeString(),
            'received_at' => $receivedAt,
            'event_hash' => $eventHash,
            'ip_hash' => $this->computeIpHash($request),
            'user_agent_family' => $this->resolveUserAgentFamily((string) $request->userAgent()),
            'device_type' => $this->resolveDeviceType((string) $request->userAgent()),
            'created_at' => $receivedAt,
        ];
    }

    private function normalizeEventType(string $eventType): ?string
    {
        $normalized = strtolower(trim($eventType));

        return match ($normalized) {
            'pageview' => 'page_view',
            'scrolldepth', 'scroll-depth' => 'scroll_depth',
            'readtime', 'read-time' => 'read_time',
            default => in_array($normalized, self::ALLOWED_EVENT_TYPES, true) ? $normalized : null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveOccurredAt(array $event): CarbonImmutable
    {
        $occurredAt = $event['occurred_at'] ?? null;
        if (is_string($occurredAt) && trim($occurredAt) !== '') {
            try {
                return CarbonImmutable::parse($occurredAt);
            } catch (\Throwable) {
            }
        }

        if (is_numeric($event['time'] ?? null)) {
            return CarbonImmutable::createFromTimestampMs((int) $event['time']);
        }

        return CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveEventDiscriminator(string $eventType, array $event): string
    {
        return match ($eventType) {
            'scroll_depth' => 'depth:'.(string) ($event['depth'] ?? data_get($event, 'meta.depth', 0)),
            'read_time' => 'session:'.(string) ($event['session_id'] ?? $event['sessionId'] ?? data_get($event, 'meta.session_id', 'none')),
            default => '',
        };
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowedDomains(AnalyticsSite $site): array
    {
        $domains = is_array($site->allowed_domains) ? $site->allowed_domains : [];
        $propertyHost = $this->extractHost((string) ($site->property?->url ?? ''));

        if ($propertyHost !== '') {
            $domains[] = $propertyHost;
        }

        return collect($domains)
            ->map(fn ($domain) => $this->extractHost((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function requestOriginAllowed(Request $request, array $allowedDomains): bool
    {
        $originHost = $this->extractHost((string) $request->header('Origin', ''));
        $refererHost = $this->extractHost((string) $request->header('Referer', ''));

        if ($originHost !== '') {
            return $this->isHostAllowed($originHost, $allowedDomains);
        }

        return $refererHost === '' || $this->isHostAllowed($refererHost, $allowedDomains);
    }

    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.'.$allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function extractHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.ltrim($value, '/');
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? strtolower(trim($host)) : '';
    }

    private function computeVisitorHash(Request $request, CarbonImmutable $occurredAt): string
    {
        return hash('sha256', ((string) config('analytics.privacy.salt', '')).$occurredAt->format('Y-m-d').'|'.((string) $request->ip()).'|'.((string) $request->userAgent()));
    }

    private function computeSessionHash(string $visitorHash, CarbonImmutable $occurredAt): string
    {
        return hash('sha256', $visitorHash.'|'.intdiv($occurredAt->timestamp, 1800));
    }

    private function computeIpHash(Request $request): string
    {
        return hash('sha256', ((string) config('analytics.privacy.salt', '')).'|ip|'.((string) $request->ip()));
    }

    private function resolveUserAgentFamily(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);

        return match (true) {
            $ua === '' => null,
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'chrome/') => 'Chrome',
            str_contains($ua, 'safari/') => 'Safari',
            str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider') => 'Bot',
            default => 'Other',
        };
    }

    private function resolveDeviceType(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);

        return match (true) {
            $ua === '' => null,
            str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider') => 'bot',
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone') => 'mobile',
            default => 'desktop',
        };
    }
}
