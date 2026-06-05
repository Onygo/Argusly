<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Jobs\Stats\RecalculateContentMetricsJob;
use App\Models\AnalyticsSite;
use App\Services\Analytics\AnalyticsContentResolver;
use App\Support\Analytics\AnalyticsUrlKey;
use App\Support\QueueNames;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    private const CONTENT_CLASSIFIED_EVENT_TYPES = [
        'page_view',
        'pageview',
        'engaged',
        'read_through',
        'scroll_depth',
        'read_time',
    ];

    public function store(Request $request, AnalyticsContentResolver $contentResolver): JsonResponse
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
                ->with('clientSite:id,site_url')
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

        $originHost = $this->extractHost((string) $request->header('Origin', ''));
        $refererHost = $this->extractHost((string) $request->header('Referer', ''));

        // Middleware already enforces this, but keep a local check for route safety.
        if ($originHost !== '' && ! $this->isHostAllowed($originHost, $allowedDomains)) {
            return response()->json(['error' => 'Origin not allowed'], 403);
        }

        if ($originHost === '' && $refererHost !== '' && ! $this->isHostAllowed($refererHost, $allowedDomains)) {
            return response()->json(['error' => 'Origin not allowed'], 403);
        }

        $rows = [];
        $invalidEventTypes = [];
        $receivedAt = now();
        $maxStringLength = (int) config('analytics.ingestion.max_string_length', 2000);
        $siteClientId = trim((string) ($site->client_site_id ?? ''));
        $resolvedContentByUrlKey = [];
        $resolvedContentByArticleId = [];
        $scrollRows = [];
        $readRows = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $rawEventType = (string) ($event['event_type'] ?? $event['type'] ?? $event['event'] ?? '');
            $eventType = $this->normalizeEventType($rawEventType);
            if ($eventType === null) {
                $invalidEventTypes[] = strtolower(trim($rawEventType));

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
                $urlKey = AnalyticsUrlKey::fromUrl((string) ($row['canonical_url'] ?? $row['url'] ?? ''));
                $row['url_key'] = $urlKey !== '' ? Str::limit($urlKey, 512, '') : null;

                if ($this->shouldResolveContentForEventType((string) $row['event_type'])) {
                    $contentId = null;
                    $articleId = trim((string) ($row['article_id'] ?? ''));

                    if ($siteClientId !== '' && $urlKey !== '') {
                        if (! array_key_exists($urlKey, $resolvedContentByUrlKey)) {
                            $resolvedContentByUrlKey[$urlKey] = $contentResolver->resolve($siteClientId, $urlKey);
                        }

                        $contentId = $resolvedContentByUrlKey[$urlKey];
                    }

                    if ($contentId === null && $siteClientId !== '' && $articleId !== '') {
                        $articleCacheKey = $siteClientId . '|' . $articleId;
                        if (! array_key_exists($articleCacheKey, $resolvedContentByArticleId)) {
                            $resolvedContentByArticleId[$articleCacheKey] = DB::table('contents')
                                ->where('id', $articleId)
                                ->where('client_site_id', $siteClientId)
                                ->value('id');
                        }

                        $resolvedByArticleId = $resolvedContentByArticleId[$articleCacheKey];
                        if (is_string($resolvedByArticleId) && $resolvedByArticleId !== '') {
                            $contentId = $resolvedByArticleId;
                        }
                    }

                    $isPublishLayerPage = (is_string($contentId) && $contentId !== '') || $articleId !== '';
                    $row['content_id'] = $contentId;
                    $row['page_type'] = $isPublishLayerPage
                        ? 'publishlayer_content'
                        : 'other_page';
                } else {
                    $row['content_id'] = null;
                    $row['page_type'] = null;
                }

                $sessionId = trim((string) ($row['_session_id'] ?? ''));
                if ($eventType === 'scroll_depth' && $sessionId !== '' && $urlKey !== '') {
                    $depth = max(0, min(100, (int) ($row['_scroll_depth'] ?? 0)));
                    if ($depth > 0) {
                        $scrollRows[] = [
                            'analytics_site_id' => $site->id,
                            'url' => (string) ($row['canonical_url'] ?? $row['url'] ?? ''),
                            'url_key' => $urlKey,
                            'session_id' => Str::limit($sessionId, 128, ''),
                            'depth' => $depth,
                            'created_at' => $receivedAt,
                        ];
                    }
                }

                if ($eventType === 'read_time' && $sessionId !== '' && $urlKey !== '') {
                    $seconds = max(0, (int) ($row['_read_seconds'] ?? 0));
                    $readRows[] = [
                        'analytics_site_id' => $site->id,
                        'url' => (string) ($row['canonical_url'] ?? $row['url'] ?? ''),
                        'url_key' => $urlKey,
                        'session_id' => Str::limit($sessionId, 128, ''),
                        'read_seconds' => $seconds,
                        'created_at' => $receivedAt,
                    ];
                }

                unset($row['_session_id'], $row['_scroll_depth'], $row['_read_seconds']);
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

        if ($scrollRows !== [] && \Illuminate\Support\Facades\Schema::hasTable('page_scroll_events')) {
            DB::table('page_scroll_events')->insertOrIgnore($scrollRows);
        }

        if ($readRows !== [] && \Illuminate\Support\Facades\Schema::hasTable('page_read_sessions')) {
            DB::table('page_read_sessions')->upsert(
                $readRows,
                ['analytics_site_id', 'url_key', 'session_id'],
                ['url', 'read_seconds']
            );
        }

        if (($stored > 0 || $scrollRows !== [] || $readRows !== []) && \Illuminate\Support\Facades\Schema::hasTable('content_metrics')) {
            $this->dispatchContentMetricRefresh((string) $site->id);
        }

        return response()->json([
            'ok' => true,
            'stored' => $stored,
            'received' => count($rows),
        ]);
    }

    private function dispatchContentMetricRefresh(string $analyticsSiteId): void
    {
        $siteId = trim($analyticsSiteId);
        if ($siteId === '') {
            return;
        }

        if (! (bool) config('analytics.metrics.refresh_on_ingest', true)) {
            return;
        }

        $throttleSeconds = max(15, (int) config('analytics.metrics.refresh_throttle_seconds', 60));
        $cacheKey = 'analytics:metrics:refresh:' . $siteId;

        $claimed = Cache::add($cacheKey, now()->getTimestamp(), now()->addSeconds($throttleSeconds));
        if (! $claimed) {
            return;
        }

        RecalculateContentMetricsJob::dispatch($siteId)
            ->onQueue(QueueNames::DEFAULT);
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

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

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
        \Illuminate\Support\Carbon $receivedAt,
        string $fallbackReferrer
    ): ?array {
        $url = $this->normalizeUrl((string) ($event['url'] ?? ''));

        if ($url === null) {
            $legacyHost = trim((string) ($event['host'] ?? ''));
            $legacyPath = '/' . ltrim(trim((string) ($event['path'] ?? '/')), '/');
            if ($legacyHost !== '') {
                $url = $this->normalizeUrl('https://' . $legacyHost . $legacyPath);
            }
        }

        $canonicalUrl = $this->normalizeUrl((string) ($event['canonical_url'] ?? $event['canonicalUrl'] ?? ''));
        if ($canonicalUrl !== null) {
            // Canonical URL is the stable page identity we use for storage and dedupe.
            $url = $canonicalUrl;
        }

        if ($canonicalUrl === null) {
            $canonicalUrl = $url;
        }

        if ($canonicalUrl === null) {
            return null;
        }

        $eventHost = $this->extractHost($canonicalUrl);
        if ($eventHost === '' && $url !== null) {
            $eventHost = $this->extractHost($url);
        }

        if ($eventHost === '' || ! $this->isHostAllowed($eventHost, $allowedDomains)) {
            return null;
        }

        $path = $this->extractPathFromUrl($canonicalUrl);
        $path = Str::limit($path === '' ? '/' : $path, $maxStringLength, '');

        $referrerRaw = (string) ($event['referrer'] ?? $fallbackReferrer);
        $referrer = $referrerRaw !== '' ? Str::limit($referrerRaw, $maxStringLength, '') : null;

        $title = trim((string) ($event['title'] ?? $event['page_title'] ?? ''));
        $title = $title !== '' ? Str::limit($title, 500, '') : null;

        $occurredAt = $this->resolveOccurredAt($event);
        $canonicalUrlHash = hash('sha256', $canonicalUrl);
        $timeBucket = intdiv($occurredAt->timestamp, 30);
        $eventDiscriminator = $this->resolveEventDiscriminator($eventType, $event);
        $eventHash = hash('sha256', implode('|', [$site->id, $canonicalUrlHash, $eventType, (string) $timeBucket, $eventDiscriminator]));

        $visitorHash = $this->computeVisitorHash($request, $occurredAt);
        $sessionHash = $this->computeSessionHash($visitorHash, $occurredAt);

        $articleId = $this->resolveArticleId($event);
        $sessionId = $this->resolveSessionId($event);
        $scrollDepth = $eventType === 'scroll_depth' ? $this->resolveScrollDepth($event) : null;
        $readSeconds = $eventType === 'read_time' ? $this->resolveReadSeconds($event) : null;

        $contentType = trim((string) ($event['content_type'] ?? $event['contentType'] ?? ''));
        $contentType = $contentType !== '' ? Str::limit($contentType, 64, '') : null;

        $metaPayload = isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : [];
        if ($sessionId !== null) {
            $metaPayload['session_id'] = $sessionId;
        }
        if ($scrollDepth !== null) {
            $metaPayload['depth'] = $scrollDepth;
        }
        if ($readSeconds !== null) {
            $metaPayload['read_seconds'] = $readSeconds;
        }
        $meta = $metaPayload !== []
            ? json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $finalUrl = Str::limit($url ?? $canonicalUrl, $maxStringLength, '');
        $finalCanonicalUrl = Str::limit($canonicalUrl, $maxStringLength, '');

        return [
            'analytics_site_id' => $site->id,
            'event_type' => $eventType,
            'visitor_hash' => $visitorHash,
            'session_hash' => $sessionHash,
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'title' => $title,
            'referrer' => $referrer,
            'host' => $eventHost,
            'article_id' => $articleId,
            'content_type' => $contentType,
            'meta' => $meta,
            'event_time' => $occurredAt->toDateTimeString(),
            'created_at' => $receivedAt,
            'received_at' => $receivedAt,
            'url' => $finalUrl,
            'canonical_url' => $finalCanonicalUrl,
            'canonical_url_hash' => $canonicalUrlHash,
            'event_hash' => $eventHash,
            'ip_hash' => $this->computeIpHash($request),
            'user_agent_family' => $this->resolveUserAgentFamily((string) $request->userAgent()),
            'device_type' => $this->resolveDeviceType((string) $request->userAgent()),
            '_session_id' => $sessionId,
            '_scroll_depth' => $scrollDepth,
            '_read_seconds' => $readSeconds,
        ];
    }

    private function shouldResolveContentForEventType(string $eventType): bool
    {
        return in_array($eventType, self::CONTENT_CLASSIFIED_EVENT_TYPES, true);
    }

    private function normalizeEventType(string $eventType): ?string
    {
        $normalized = strtolower(trim($eventType));

        if ($normalized === 'pageview') {
            return 'page_view';
        }

        if ($normalized === 'scrolldepth' || $normalized === 'scroll-depth') {
            return 'scroll_depth';
        }

        if ($normalized === 'readtime' || $normalized === 'read-time') {
            return 'read_time';
        }

        if (! in_array($normalized, self::ALLOWED_EVENT_TYPES, true)) {
            return null;
        }

        return $normalized;
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
                // Fall back to remaining parsers.
            }
        }

        $legacyTime = $event['time'] ?? null;
        if (is_numeric($legacyTime)) {
            $milliseconds = (int) $legacyTime;

            return CarbonImmutable::createFromTimestampMs($milliseconds);
        }

        return CarbonImmutable::now();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowedDomains(AnalyticsSite $site): array
    {
        $domains = $site->allowed_domains;
        if (! is_array($domains)) {
            $domains = [];
        }

        $siteHost = $this->extractHost((string) ($site->clientSite?->site_url ?? ''));
        if ($siteHost !== '') {
            $domains[] = $siteHost;
        }

        return collect($domains)
            ->map(fn ($domain) => $this->extractHost((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain) {
                return true;
            }

            if (str_ends_with($host, '.' . $allowedDomain)) {
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
            $value = 'https://' . ltrim($value, '/');
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? strtolower(trim($host)) : '';
    }

    private function normalizeUrl(string $url): ?string
    {
        return AnalyticsUrlKey::normalizeUrl($url);
    }

    private function extractPathFromUrl(string $url): string
    {
        $path = AnalyticsUrlKey::pathFromUrl($url);

        return $path !== '' ? $path : '/';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveArticleId(array $event): ?string
    {
        $candidates = [
            $event['article_id'] ?? null,
            $event['articleId'] ?? null,
            $event['publishlayer_article_id'] ?? null,
            $event['publishlayerArticleId'] ?? null,
            data_get($event, 'meta.publishlayer_article_id'),
            data_get($event, 'meta.publishlayerArticleId'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && Str::isUuid($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveSessionId(array $event): ?string
    {
        $sessionId = trim((string) ($event['session_id'] ?? $event['sessionId'] ?? data_get($event, 'meta.session_id', '')));

        return $sessionId !== '' ? Str::limit($sessionId, 128, '') : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveScrollDepth(array $event): ?int
    {
        $depth = $event['depth'] ?? data_get($event, 'meta.depth');
        if (! is_numeric($depth)) {
            return null;
        }

        return max(0, min(100, (int) $depth));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveReadSeconds(array $event): ?int
    {
        $seconds = $event['seconds'] ?? $event['read_seconds'] ?? $event['readSeconds'] ?? data_get($event, 'meta.read_seconds');
        if (! is_numeric($seconds)) {
            return null;
        }

        return max(0, (int) $seconds);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveEventDiscriminator(string $eventType, array $event): string
    {
        return match ($eventType) {
            'scroll_depth' => 'depth:' . (string) ($this->resolveScrollDepth($event) ?? 0),
            'read_time' => 'session:' . (string) ($this->resolveSessionId($event) ?? 'none'),
            default => '',
        };
    }

    private function computeVisitorHash(Request $request, CarbonImmutable $occurredAt): string
    {
        $salt = (string) config('analytics.privacy.salt', '');
        $dailySalt = $salt . $occurredAt->format('Y-m-d');
        $ip = (string) ($request->ip() ?? 'unknown');
        $ua = (string) ($request->userAgent() ?? 'unknown');

        return hash('sha256', $dailySalt . '|' . $ip . '|' . $ua);
    }

    private function computeSessionHash(string $visitorHash, CarbonImmutable $occurredAt): string
    {
        $slot = intdiv($occurredAt->timestamp, 1800);

        return hash('sha256', $visitorHash . '|' . $slot);
    }

    private function computeIpHash(Request $request): string
    {
        $salt = (string) config('analytics.privacy.salt', '');
        $ip = (string) ($request->ip() ?? 'unknown');

        return hash('sha256', $salt . '|ip|' . $ip);
    }

    private function resolveUserAgentFamily(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'edg/')) {
            return 'Edge';
        }

        if (str_contains($ua, 'firefox/')) {
            return 'Firefox';
        }

        if (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
            return 'Opera';
        }

        if (str_contains($ua, 'chrome/')) {
            return 'Chrome';
        }

        if (str_contains($ua, 'safari/')) {
            return 'Safari';
        }

        if (str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider')) {
            return 'Bot';
        }

        return 'Other';
    }

    private function resolveDeviceType(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'bot') || str_contains($ua, 'crawler') || str_contains($ua, 'spider')) {
            return 'bot';
        }

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
