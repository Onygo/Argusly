<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageSnapshot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class PageFetcher
{
    private const FETCHER_VERSION = 'page-fetcher-v1';

    public function __construct(
        private readonly PageUrlNormalizer $normalizer,
        private readonly PageCrawlerSafetyService $safety,
    ) {
    }

    public function fetch(MonitoredPage $page, ?string $requestedUrl = null): PageFetchResult
    {
        $page = ($page->fresh() ?? $page)->loadMissing('source');
        $source = $page->source instanceof MonitoredSource ? $page->source : null;
        $startedAt = microtime(true);
        $fetchedAt = Carbon::now();
        $url = $this->requestedUrl($page, $requestedUrl);

        try {
            $url = $this->safety->normalizeAndValidate($url, $source);
        } catch (InvalidArgumentException $exception) {
            return $this->storeFailedSnapshot(
                page: $page,
                requestedUrl: $url,
                errorCode: 'PAGE_FETCH_BLOCKED',
                errorMessage: $exception->getMessage(),
                startedAt: $startedAt,
                fetchedAt: $fetchedAt,
            );
        }

        try {
            $response = $this->sendRequest($url, $source);
        } catch (InvalidArgumentException $exception) {
            return $this->storeFailedSnapshot(
                page: $page,
                requestedUrl: $url,
                errorCode: 'PAGE_FETCH_REDIRECT_BLOCKED',
                errorMessage: 'The fetch redirected to a private or internal URL.',
                startedAt: $startedAt,
                fetchedAt: $fetchedAt,
                metadata: [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
            );
        } catch (Throwable $exception) {
            return $this->storeFailedSnapshot(
                page: $page,
                requestedUrl: $url,
                errorCode: $this->errorCodeForException($exception),
                errorMessage: $exception->getMessage(),
                startedAt: $startedAt,
                fetchedAt: $fetchedAt,
                metadata: [
                    'exception_class' => $exception::class,
                ],
            );
        }

        $finalUrl = $this->finalUrl($response, $url);
        try {
            $this->safety->validateRedirectTarget($finalUrl, $source);
        } catch (InvalidArgumentException $exception) {
            return $this->storeFailedSnapshot(
                page: $page,
                requestedUrl: $url,
                finalUrl: $finalUrl,
                httpStatus: $response->status(),
                contentType: $this->contentType($response),
                responseHeaders: $this->headers($response),
                redirectChain: $this->redirectChain($response, $url, $finalUrl),
                errorCode: 'PAGE_FETCH_REDIRECT_BLOCKED',
                errorMessage: 'The fetch redirected to a private or internal URL.',
                startedAt: $startedAt,
                fetchedAt: $fetchedAt,
            );
        }

        $contentType = $this->contentType($response);
        $body = (string) $response->body();
        $bodyBytes = strlen($body);
        $errorCode = null;
        $errorMessage = null;

        if ($response->failed()) {
            $errorCode = $this->errorCodeForStatus($response->status());
            $errorMessage = sprintf('HTTP %d returned while fetching the monitored page.', $response->status());
        } elseif ($bodyBytes === 0) {
            $errorCode = 'PAGE_FETCH_EMPTY';
            $errorMessage = 'The fetch returned an empty response body.';
        } else {
            try {
                $this->safety->assertResponseAllowed(
                    response: $response,
                    url: $url,
                    allowedContentTypes: ['text/html', 'application/xhtml+xml', 'text/plain'],
                    maxBytes: $this->maxHtmlBytes(),
                    source: $source,
                );
            } catch (InvalidArgumentException $exception) {
                $message = $exception->getMessage();
                $errorCode = str_contains(strtolower($message), 'size limit')
                    ? 'PAGE_FETCH_TOO_LARGE'
                    : 'PAGE_FETCH_UNSUPPORTED_CONTENT_TYPE';
                $errorMessage = $message;
            }
        }

        $successful = $errorCode === null;
        $htmlToStore = $bodyBytes > 0 && $bodyBytes <= $this->maxHtmlBytes() ? $body : null;
        $rawHtmlHash = $htmlToStore !== null ? hash('sha256', $htmlToStore) : null;

        return $this->storeSnapshot(
            page: $page,
            requestedUrl: $url,
            finalUrl: $finalUrl,
            canonicalUrl: $page->canonical_url,
            httpStatus: $response->status(),
            contentType: $contentType,
            responseHeaders: $this->headers($response),
            redirectChain: $this->redirectChain($response, $url, $finalUrl),
            rawHtml: $successful ? $htmlToStore : null,
            rawHtmlHash: $successful ? $rawHtmlHash : null,
            successful: $successful,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            startedAt: $startedAt,
            fetchedAt: $fetchedAt,
            metadata: [
                'response_bytes' => $bodyBytes,
                'storage_mode' => (string) config('page_intelligence.fetch.raw_html_storage', 'disk'),
            ],
        );
    }

    private function sendRequest(string $url, ?MonitoredSource $source): Response
    {
        return Http::timeout($this->timeoutSeconds())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->withUserAgent((string) config('page_intelligence.fetch.user_agent', 'ArguslyPageIntelligence/1.0 (+https://argusly.com)'))
            ->accept('text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1')
            ->withOptions(array_replace_recursive($this->safety->guardedHttpOptions($url, $source), [
                'allow_redirects' => [
                    'max' => $this->redirectLimit(),
                    'strict' => false,
                    'referer' => true,
                    'track_redirects' => true,
                    'on_redirect' => function ($request, $response, $uri) use ($source): void {
                        unset($request, $response);
                        $this->safety->validateRedirectTarget((string) $uri, $source);
                    },
                ],
            ]))
            ->get($url);
    }

    private function storeFailedSnapshot(
        MonitoredPage $page,
        string $requestedUrl,
        string $errorCode,
        string $errorMessage,
        float $startedAt,
        Carbon $fetchedAt,
        ?string $finalUrl = null,
        ?int $httpStatus = null,
        ?string $contentType = null,
        array $responseHeaders = [],
        array $redirectChain = [],
        array $metadata = [],
    ): PageFetchResult {
        return $this->storeSnapshot(
            page: $page,
            requestedUrl: $requestedUrl,
            finalUrl: $finalUrl,
            canonicalUrl: $page->canonical_url,
            httpStatus: $httpStatus,
            contentType: $contentType,
            responseHeaders: $responseHeaders,
            redirectChain: $redirectChain,
            rawHtml: null,
            rawHtmlHash: null,
            successful: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            startedAt: $startedAt,
            fetchedAt: $fetchedAt,
            metadata: $metadata,
        );
    }

    private function storeSnapshot(
        MonitoredPage $page,
        string $requestedUrl,
        ?string $finalUrl,
        ?string $canonicalUrl,
        ?int $httpStatus,
        ?string $contentType,
        array $responseHeaders,
        array $redirectChain,
        ?string $rawHtml,
        ?string $rawHtmlHash,
        bool $successful,
        ?string $errorCode,
        ?string $errorMessage,
        float $startedAt,
        Carbon $fetchedAt,
        array $metadata = [],
    ): PageFetchResult {
        return DB::transaction(function () use (
            $page,
            $requestedUrl,
            $finalUrl,
            $canonicalUrl,
            $httpStatus,
            $contentType,
            $responseHeaders,
            $redirectChain,
            $rawHtml,
            $rawHtmlHash,
            $successful,
            $errorCode,
            $errorMessage,
            $startedAt,
            $fetchedAt,
            $metadata,
        ): PageFetchResult {
            $page = MonitoredPage::query()
                ->whereKey($page->id)
                ->lockForUpdate()
                ->firstOrFail();

            $latestSnapshot = PageSnapshot::query()
                ->where('monitored_page_id', $page->id)
                ->orderByDesc('snapshot_number')
                ->lockForUpdate()
                ->first();

            $snapshotNumber = ((int) ($latestSnapshot?->snapshot_number ?? 0)) + 1;
            $contentChanged = $successful && $rawHtmlHash !== null
                ? $latestSnapshot === null || (string) $latestSnapshot->raw_html_hash !== $rawHtmlHash
                : false;
            $storage = $this->storeRawHtml($page, $snapshotNumber, $rawHtml);

            $snapshot = PageSnapshot::query()->create([
                'organization_id' => $page->organization_id,
                'workspace_id' => $page->workspace_id,
                'client_site_id' => $page->client_site_id,
                'monitored_page_id' => $page->id,
                'snapshot_number' => $snapshotNumber,
                'requested_url' => $requestedUrl,
                'final_url' => $finalUrl,
                'canonical_url' => $canonicalUrl,
                'http_status' => $httpStatus,
                'content_type' => $contentType,
                'response_headers_json' => $responseHeaders,
                'redirect_chain_json' => $redirectChain,
                'raw_html_path' => $storage['path'],
                'raw_html' => $storage['inline'],
                'raw_html_bytes' => $rawHtml !== null ? strlen($rawHtml) : null,
                'raw_html_preview' => $rawHtml !== null ? mb_substr($rawHtml, 0, $this->rawHtmlPreviewBytes()) : null,
                'raw_html_hash' => $rawHtmlHash,
                'text_hash' => null,
                'content_changed' => $contentChanged,
                'canonical_conflict' => false,
                'fetch_duration_ms' => $this->durationMs($startedAt),
                'fetched_at' => $fetchedAt,
                'fetcher_version' => self::FETCHER_VERSION,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'metadata_json' => array_filter(array_merge($metadata, [
                    'successful' => $successful,
                    'timeout_seconds' => $this->timeoutSeconds(),
                    'connect_timeout_seconds' => $this->connectTimeoutSeconds(),
                    'max_html_bytes' => $this->maxHtmlBytes(),
                    'redirect_limit' => $this->redirectLimit(),
                ]), static fn (mixed $value): bool => $value !== null),
            ]);

            $page->forceFill([
                'final_url' => $finalUrl ?: $page->final_url,
                'final_url_hash' => $finalUrl ? hash('sha256', $finalUrl) : $page->final_url_hash,
                'last_fetched_at' => $fetchedAt,
                'last_changed_at' => $contentChanged ? $fetchedAt : $page->last_changed_at,
                'crawl_status' => $successful
                    ? MonitoredPage::CRAWL_STATUS_FETCHED
                    : MonitoredPage::CRAWL_STATUS_FAILED,
            ])->save();

            return new PageFetchResult($page->refresh(), $snapshot, $successful);
        });
    }

    /**
     * @return array{inline:?string,path:?string}
     */
    private function storeRawHtml(MonitoredPage $page, int $snapshotNumber, ?string $rawHtml): array
    {
        if ($rawHtml === null) {
            return ['inline' => null, 'path' => null];
        }

        if ((string) config('page_intelligence.fetch.raw_html_storage', 'disk') !== 'disk') {
            return ['inline' => $rawHtml, 'path' => null];
        }

        $path = trim((string) config('page_intelligence.fetch.raw_html_path', 'page-snapshots'), '/')
            . '/' . $page->id . '/' . $snapshotNumber . '.html';

        Storage::disk((string) config('page_intelligence.fetch.raw_html_disk', 'local'))->put($path, $rawHtml);

        return ['inline' => null, 'path' => $path];
    }

    private function requestedUrl(MonitoredPage $page, ?string $requestedUrl): string
    {
        return trim((string) ($requestedUrl ?: $page->final_url ?: $page->canonical_url ?: $page->first_seen_url));
    }

    private function finalUrl(Response $response, string $requestedUrl): string
    {
        $history = $this->redirectHistory($response);
        if ($history !== []) {
            return (string) end($history);
        }

        return (string) ($response->effectiveUri() ?: $requestedUrl);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function redirectChain(Response $response, string $requestedUrl, string $finalUrl): array
    {
        $history = $this->redirectHistory($response);
        $statuses = $this->redirectStatuses($response);

        if ($history === [] && $finalUrl !== $requestedUrl) {
            return [[
                'from' => $requestedUrl,
                'to' => $finalUrl,
                'status' => null,
            ]];
        }

        $chain = [];
        $from = $requestedUrl;
        foreach ($history as $index => $to) {
            $chain[] = [
                'from' => $from,
                'to' => $to,
                'status' => isset($statuses[$index]) ? (int) $statuses[$index] : null,
            ];
            $from = $to;
        }

        return $chain;
    }

    /**
     * @return array<int,string>
     */
    private function redirectHistory(Response $response): array
    {
        $historyHeader = (string) $response->header('X-Guzzle-Redirect-History');
        if ($historyHeader !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $historyHeader))));
        }

        $stats = $response->handlerStats();
        if ((int) ($stats['redirect_count'] ?? 0) > 0) {
            $effective = (string) ($response->effectiveUri() ?: '');

            return $effective !== '' ? [$effective] : [];
        }

        return [];
    }

    /**
     * @return array<int,int>
     */
    private function redirectStatuses(Response $response): array
    {
        $statusHeader = (string) $response->header('X-Guzzle-Redirect-Status-History');
        if ($statusHeader === '') {
            return [];
        }

        return collect(explode(',', $statusHeader))
            ->map(fn (string $status): int => (int) trim($status))
            ->filter(fn (int $status): bool => $status > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function headers(Response $response): array
    {
        return collect($response->headers())
            ->map(fn (array $values): array => array_values($values))
            ->all();
    }

    private function contentType(Response $response): string
    {
        return strtolower(trim((string) $response->header('Content-Type', '')));
    }

    private function errorCodeForStatus(int $status): string
    {
        if (in_array($status, [401, 403, 429], true)) {
            return 'PAGE_FETCH_BLOCKED';
        }

        if ($status >= 500) {
            return 'PAGE_FETCH_SERVER_ERROR';
        }

        return 'PAGE_FETCH_HTTP_ERROR';
    }

    private function errorCodeForException(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
            return 'PAGE_FETCH_TIMEOUT';
        }

        if (str_contains($message, 'too many redirects') || str_contains($message, 'curl error 47')) {
            return 'PAGE_FETCH_REDIRECT_LOOP';
        }

        return 'PAGE_FETCH_FAILED';
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('page_intelligence.fetch.timeout_seconds', 30));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('page_intelligence.fetch.connect_timeout_seconds', 10));
    }

    private function maxHtmlBytes(): int
    {
        return max(1, (int) config('page_intelligence.fetch.max_html_bytes', 3000000));
    }

    private function redirectLimit(): int
    {
        return max(0, (int) config('page_intelligence.fetch.redirect_limit', 5));
    }

    private function rawHtmlPreviewBytes(): int
    {
        return max(0, (int) config('page_intelligence.fetch.raw_html_preview_bytes', 2000));
    }
}
