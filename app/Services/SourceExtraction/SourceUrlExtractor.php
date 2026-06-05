<?php

namespace App\Services\SourceExtraction;

use App\Models\SourceExtraction;
use App\Services\SourceBriefing\ArticleContentExtractor;
use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SourceUrlExtractor
{
    public function __construct(
        private readonly ArticleContentExtractor $articleExtractor,
    ) {}

    /**
     * @param mixed $tenant Tenant-like object/scalar. Organization/workspace ids are supported.
     * @param array<string, mixed> $options
     */
    public function extract(string $url, $tenant = null, array $options = []): SourceExtractionResult
    {
        $startedAt = microtime(true);
        $normalized = $this->normalizeUrl($url);
        $tenantId = $this->tenantId($tenant);

        if ($normalized === null) {
            return $this->failure($url, 'SOURCE_URL_INVALID', 'Enter a valid public article URL.', $startedAt);
        }

        if ($this->isBlockedUrl($normalized)) {
            return $this->failure($normalized, 'SOURCE_FETCH_BLOCKED', 'This URL cannot be analyzed. Use a public article URL.', $startedAt);
        }

        if (($options['use_cache'] ?? true) !== false && $this->cacheTableExists()) {
            $cached = $this->cachedResult($normalized, $tenantId);
            if ($cached instanceof SourceExtractionResult) {
                return $cached;
            }
        }

        $attempts = [];
        $methods = ['direct', 'direct_relaxed'];
        if ((bool) ($options['jina_enabled'] ?? config('source_extraction.jina_enabled', true))) {
            $methods[] = 'jina_reader';
        }
        if ((bool) ($options['browser_enabled'] ?? config('source_extraction.browser_enabled', false))) {
            $methods[] = 'browser_render';
        }

        foreach ($methods as $method) {
            $result = match ($method) {
                'direct' => $this->extractDirect($normalized, $tenant, $options, false),
                'direct_relaxed' => $this->extractDirect($normalized, $tenant, $options, true),
                'jina_reader' => $this->extractViaJina($normalized, $tenant, $options),
                'browser_render' => $this->extractViaBrowser($normalized, $tenant, $options),
                default => null,
            };

            if (! $result instanceof SourceExtractionResult) {
                continue;
            }

            $attempts[] = [
                'method' => $method,
                'success' => $result->success,
                'error_code' => $result->errorCode,
                'status_code' => data_get($result->metadata, 'status_code'),
                'duration_ms' => $result->durationMs,
            ];

            if ($result->success) {
                $result = $this->withMetadata($result, [
                    'attempts' => $attempts,
                    'cache_hit' => false,
                ]);
                $this->storeResult($result, $tenantId);

                return $result;
            }

            if (! $this->shouldFallback($result)) {
                break;
            }
        }

        $last = $this->preferredFailureAttempt($attempts);
        $result = $this->failure(
            $normalized,
            (string) ($last['error_code'] ?? 'SOURCE_EXTRACTION_FAILED'),
            $this->messageForCode((string) ($last['error_code'] ?? 'SOURCE_EXTRACTION_FAILED')),
            $startedAt,
            null,
            ['attempts' => $attempts]
        );
        $this->storeResult($result, $tenantId);

        return $result;
    }

    public function buildJinaReaderUrl(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === 'r.jina.ai') {
            return $normalized;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = trim((string) ($parts['query'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $target = $host . $port . ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');

        return 'https://r.jina.ai/http://' . ltrim($target, '/');
    }

    /**
     * @param mixed $tenant
     * @param array<string, mixed> $options
     */
    private function extractDirect(string $url, $tenant, array $options, bool $relaxed): SourceExtractionResult
    {
        $startedAt = microtime(true);
        $method = $relaxed ? 'direct_relaxed' : 'direct';
        $timeout = (int) ($relaxed
            ? ($options['relaxed_timeout_seconds'] ?? config('source_extraction.relaxed_timeout_seconds', 60))
            : ($options['direct_timeout_seconds'] ?? config('source_extraction.direct_timeout_seconds', 30)));
        $connectTimeout = (int) ($options['connect_timeout_seconds'] ?? config('source_extraction.connect_timeout_seconds', 10));

        Log::info('source_extraction.fetch_start', [
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'method' => $method,
            'tenant_id' => $this->tenantId($tenant),
            'timeout_seconds' => $timeout,
        ]);

        try {
            $response = null;
            $maxAttempts = $relaxed ? 3 : 1;
            foreach (range(1, $maxAttempts) as $attempt) {
                $response = Http::timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->withOptions(['allow_redirects' => true])
                    ->withHeaders($this->browserHeaders())
                    ->get($url);

                if (! in_array($response->status(), [429, 503], true) || $attempt === $maxAttempts) {
                    break;
                }

                usleep($attempt * 500_000);
            }
        } catch (Throwable $exception) {
            $code = $this->isTimeout($exception->getMessage()) ? 'SOURCE_FETCH_TIMEOUT' : 'SOURCE_FETCH_FAILED';

            return $this->failure($url, $code, $this->messageForCode($code), $startedAt, $method, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        if (! $response instanceof Response) {
            return $this->failure($url, 'SOURCE_FETCH_FAILED', $this->messageForCode('SOURCE_FETCH_FAILED'), $startedAt, $method);
        }

        $body = (string) $response->body();
        $bodyBytes = strlen($body);
        $status = $response->status();
        $finalUrl = (string) ($response->effectiveUri() ?: $url);

        Log::info('source_extraction.fetch_end', [
            'url' => $url,
            'final_url' => $finalUrl,
            'domain' => parse_url($url, PHP_URL_HOST),
            'method' => $method,
            'tenant_id' => $this->tenantId($tenant),
            'status_code' => $status,
            'content_length' => $bodyBytes,
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        if ($response->failed()) {
            $code = in_array($status, [401, 403, 429], true) ? 'SOURCE_FETCH_BLOCKED' : 'SOURCE_FETCH_UNAVAILABLE';

            return $this->failure($url, $code, $this->messageForCode($code), $startedAt, $method, [
                'status_code' => $status,
                'final_url' => $finalUrl,
            ]);
        }

        if ($bodyBytes === 0) {
            return $this->failure($url, 'SOURCE_FETCH_EMPTY', $this->messageForCode('SOURCE_FETCH_EMPTY'), $startedAt, $method, [
                'status_code' => $status,
                'final_url' => $finalUrl,
            ]);
        }

        if ($bodyBytes > (int) config('source_extraction.max_html_bytes', 3000000)) {
            return $this->failure($url, 'SOURCE_PAGE_TOO_LARGE', $this->messageForCode('SOURCE_PAGE_TOO_LARGE'), $startedAt, $method, [
                'status_code' => $status,
                'final_url' => $finalUrl,
                'content_length' => $bodyBytes,
            ]);
        }

        return $this->parseHtml($url, $finalUrl, $body, $method, $startedAt, [
            'status_code' => $status,
            'content_type' => $response->header('Content-Type'),
            'content_length' => $bodyBytes,
            'min_text_chars' => $options['min_text_chars'] ?? null,
        ]);
    }

    /**
     * @param mixed $tenant
     * @param array<string, mixed> $options
     */
    private function extractViaJina(string $url, $tenant, array $options): SourceExtractionResult
    {
        $startedAt = microtime(true);
        $jinaUrl = $this->buildJinaReaderUrl($url);
        if ($jinaUrl === null) {
            return $this->failure($url, 'SOURCE_URL_INVALID', $this->messageForCode('SOURCE_URL_INVALID'), $startedAt, 'jina_reader');
        }

        Log::info('source_extraction.fetch_start', [
            'url' => $url,
            'jina_url' => $jinaUrl,
            'domain' => parse_url($url, PHP_URL_HOST),
            'method' => 'jina_reader',
            'tenant_id' => $this->tenantId($tenant),
            'has_api_key' => $this->jinaApiKey() !== null,
        ]);

        try {
            $request = Http::timeout((int) ($options['relaxed_timeout_seconds'] ?? config('source_extraction.relaxed_timeout_seconds', 60)))
                ->connectTimeout((int) config('source_extraction.connect_timeout_seconds', 10))
                ->accept('text/plain, text/markdown, application/json, */*;q=0.5');

            if ($this->jinaApiKey() !== null) {
                $request = $request->withToken($this->jinaApiKey());
            }

            $response = $request->get($jinaUrl);
        } catch (Throwable $exception) {
            return $this->failure($url, 'SOURCE_JINA_FAILED', 'Reader fallback could not extract this source.', $startedAt, 'jina_reader', [
                'jina_url' => $jinaUrl,
                'has_api_key' => $this->jinaApiKey() !== null,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        if ($response->failed()) {
            $authRequired = in_array($response->status(), [401, 403], true)
                && str_contains(strtolower((string) $response->body()), 'authentication');

            return $this->failure(
                $url,
                $authRequired ? 'SOURCE_JINA_AUTH_REQUIRED' : 'SOURCE_JINA_FAILED',
                $authRequired
                    ? 'Reader fallback requires a Jina API key. Configure SOURCE_EXTRACTION_JINA_API_KEY or disable Jina fallback.'
                    : 'Reader fallback could not extract this source.',
                $startedAt,
                'jina_reader',
                [
                'jina_url' => $jinaUrl,
                'has_api_key' => $this->jinaApiKey() !== null,
                'status_code' => $response->status(),
                'response_excerpt' => Str::limit((string) $response->body(), 500, ''),
                ]
            );
        }

        $text = $this->normalizeWhitespace((string) $response->body());
        if ($text === '' || mb_strlen($text) < (int) ($options['min_text_chars'] ?? config('source_extraction.min_text_chars', 800))) {
            return $this->failure($url, 'SOURCE_EXTRACTION_TOO_SHORT', $this->messageForCode('SOURCE_EXTRACTION_TOO_SHORT'), $startedAt, 'jina_reader', [
                'jina_url' => $jinaUrl,
                'chars' => mb_strlen($text),
            ]);
        }

        $title = $this->extractMarkdownTitle((string) $response->body());
        $text = $this->trimText($text);
        $chars = mb_strlen($text);

        return new SourceExtractionResult(
            success: true,
            url: $url,
            finalUrl: $url,
            title: $title,
            summary: Str::limit($text, 280, ''),
            extractedText: $text,
            wordCount: str_word_count($text),
            chars: $chars,
            estimatedTokens: $this->estimateTokens($text),
            method: 'jina_reader',
            durationMs: $this->durationMs($startedAt),
            metadata: [
                'jina_url' => $jinaUrl,
                'source_reliability' => [
                    'domain' => parse_url($url, PHP_URL_HOST),
                    'method' => 'jina_reader',
                    'status_code' => $response->status(),
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            ],
        );
    }

    /**
     * @param mixed $tenant
     * @param array<string, mixed> $options
     */
    private function extractViaBrowser(string $url, $tenant, array $options): SourceExtractionResult
    {
        $startedAt = microtime(true);

        if (! (bool) config('source_extraction.browser_enabled', false)) {
            return $this->failure($url, 'SOURCE_BROWSER_DISABLED', 'Browser extraction is disabled.', $startedAt, 'browser_render', [
                'skipped' => true,
                'tenant_id' => $this->tenantId($tenant),
                'options' => array_intersect_key($options, ['browser_enabled' => true]),
            ]);
        }

        if (! class_exists(\Spatie\Browsershot\Browsershot::class)) {
            return $this->failure($url, 'SOURCE_BROWSER_UNAVAILABLE', 'Browser extraction is not available on this server.', $startedAt, 'browser_render', [
                'skipped' => true,
            ]);
        }

        try {
            $html = \Spatie\Browsershot\Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->timeout((int) config('source_extraction.relaxed_timeout_seconds', 60))
                ->bodyHtml();

            return $this->parseHtml($url, $url, (string) $html, 'browser_render', $startedAt, [
                'rendered' => true,
            ]);
        } catch (Throwable $exception) {
            return $this->failure($url, 'SOURCE_BROWSER_FAILED', 'Browser extraction could not extract this source.', $startedAt, 'browser_render', [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function parseHtml(string $url, string $finalUrl, string $html, string $method, float $startedAt, array $metadata = []): SourceExtractionResult
    {
        try {
            $extraction = $this->articleExtractor->extract($html, $finalUrl, 'default', [
                'url' => $url,
                'method' => $method,
            ]);
        } catch (SourceBriefingException $exception) {
            return $this->failure($url, $exception->failureCode, $exception->userMessage, $startedAt, $method, array_merge($metadata, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]));
        } catch (Throwable $exception) {
            return $this->failure($url, 'SOURCE_EXTRACTION_FAILED', $this->messageForCode('SOURCE_EXTRACTION_FAILED'), $startedAt, $method, array_merge($metadata, [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]));
        }

        $text = $this->trimText((string) $extraction['plain_text']);
        $chars = mb_strlen($text);
        if ($chars < (int) ($metadata['min_text_chars'] ?? config('source_extraction.min_text_chars', 800))) {
            return $this->failure($url, 'SOURCE_EXTRACTION_TOO_SHORT', $this->messageForCode('SOURCE_EXTRACTION_TOO_SHORT'), $startedAt, $method, array_merge($metadata, [
                    'chars' => $chars,
                ]));
        }

        return new SourceExtractionResult(
            success: true,
            url: $url,
            finalUrl: (string) ($extraction['final_url'] ?: $finalUrl),
            title: (string) ($extraction['title'] ?? ''),
            author: $extraction['author'] ?? null,
            publishedAt: $extraction['publish_date'] ?? null,
            language: (string) ($extraction['detected_language'] ?? ''),
            summary: (string) ($extraction['summary'] ?? ''),
            extractedText: $text,
            html: $html,
            wordCount: (int) ($extraction['word_count'] ?? str_word_count($text)),
            chars: $chars,
            estimatedTokens: $this->estimateTokens($text),
            method: $method,
            durationMs: $this->durationMs($startedAt),
            metadata: array_merge($metadata, [
                'canonical_url' => $extraction['canonical_url'] ?? null,
                'h1' => $extraction['h1'] ?? null,
                'outline' => $extraction['outline'] ?? [],
                'quality' => $extraction['quality'] ?? [],
                'source_reliability' => [
                    'domain' => parse_url($url, PHP_URL_HOST),
                    'method' => $method,
                    'status_code' => $metadata['status_code'] ?? null,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            ]),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function failure(string $url, string $code, string $message, float $startedAt, ?string $method = null, array $metadata = []): SourceExtractionResult
    {
        Log::warning('source_extraction.failed', [
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'method' => $method,
            'error_code' => $code,
            'error_message' => $message,
            'status_code' => $metadata['status_code'] ?? null,
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return new SourceExtractionResult(
            success: false,
            url: $url,
            method: $method,
            errorCode: $code,
            errorMessage: $message,
            durationMs: $this->durationMs($startedAt),
            metadata: array_merge($metadata, [
                'source_reliability' => [
                    'domain' => parse_url($url, PHP_URL_HOST),
                    'method' => $method,
                    'error_code' => $code,
                    'status_code' => $metadata['status_code'] ?? null,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            ]),
        );
    }

    private function shouldFallback(SourceExtractionResult $result): bool
    {
        return in_array((string) $result->errorCode, [
            'SOURCE_FETCH_TIMEOUT',
            'SOURCE_FETCH_BLOCKED',
            'SOURCE_FETCH_EMPTY',
            'SOURCE_FETCH_UNAVAILABLE',
            'SOURCE_EXTRACTION_EMPTY',
            'SOURCE_EXTRACTION_TOO_SHORT',
            'SOURCE_EXTRACTION_UNSUPPORTED_STRUCTURE',
            'SOURCE_EXTRACTION_FAILED',
            'SOURCE_JINA_AUTH_REQUIRED',
            'SOURCE_JINA_FAILED',
        ], true);
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     * @return array<string, mixed>
     */
    private function preferredFailureAttempt(array $attempts): array
    {
        foreach (['SOURCE_FETCH_TIMEOUT', 'SOURCE_FETCH_BLOCKED', 'SOURCE_FETCH_UNAVAILABLE'] as $code) {
            foreach ($attempts as $attempt) {
                if (($attempt['error_code'] ?? null) === $code) {
                    return $attempt;
                }
            }
        }

        $last = end($attempts);

        return is_array($last) ? $last : [];
    }

    private function normalizeUrl(string $url): ?string
    {
        $candidate = trim($url);
        if ($candidate === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, (array) config('source_extraction.allowed_schemes', ['http', 'https']), true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = trim((string) ($parts['query'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port . ($path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '');
    }

    private function isBlockedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (in_array($host, array_map('strtolower', (array) config('source_extraction.blocked_domains', [])), true)) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,nl;q=0.8',
            'Cache-Control' => 'no-cache',
        ];
    }

    private function cachedResult(string $url, ?int $tenantId): ?SourceExtractionResult
    {
        if (! $this->cacheTableExists()) {
            return null;
        }

        $cache = SourceExtraction::query()
            ->where('url_hash', $this->urlHash($url))
            ->where(function ($query) use ($tenantId): void {
                $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
            })
            ->where('status', 'succeeded')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('fetched_at')
            ->first();

        if (! $cache instanceof SourceExtraction) {
            return null;
        }

        return new SourceExtractionResult(
            success: true,
            url: (string) $cache->url,
            finalUrl: $cache->final_url,
            title: $cache->title,
            author: $cache->author,
            publishedAt: $cache->published_at,
            language: $cache->language,
            summary: $cache->summary,
            extractedText: $cache->extracted_text,
            wordCount: (int) $cache->word_count,
            chars: (int) $cache->chars,
            estimatedTokens: (int) $cache->estimated_tokens,
            method: $cache->method,
            durationMs: 0,
            metadata: array_merge((array) $cache->metadata, ['cache_hit' => true]),
        );
    }

    private function storeResult(SourceExtractionResult $result, ?int $tenantId): void
    {
        if (! $this->cacheTableExists()) {
            Log::warning('source_extraction.cache_skipped', [
                'url' => $result->url,
                'reason' => 'source_extractions_table_missing',
                'method' => $result->method,
                'status' => $result->success ? 'succeeded' : 'failed',
            ]);

            return;
        }

        SourceExtraction::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'url_hash' => $this->urlHash($result->url),
            ],
            [
                'url' => $result->url,
                'final_url' => $result->finalUrl,
                'title' => $result->title ? Str::limit($result->title, 250, '') : null,
                'author' => $result->author ? Str::limit($result->author, 250, '') : null,
                'published_at' => $this->dateOrNull($result->publishedAt),
                'language' => $result->language,
                'summary' => $result->summary,
                'extracted_text' => $result->extractedText,
                'word_count' => $result->wordCount,
                'chars' => $result->chars,
                'estimated_tokens' => $result->estimatedTokens,
                'method' => $result->method,
                'status' => $result->success ? 'succeeded' : 'failed',
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'duration_ms' => $result->durationMs,
                'metadata' => $result->metadata,
                'fetched_at' => now(),
                'expires_at' => now()->addDays((int) config('source_extraction.cache_ttl_days', 14)),
            ]
        );
    }

    private function cacheTableExists(): bool
    {
        try {
            return Schema::hasTable('source_extractions');
        } catch (Throwable) {
            return false;
        }
    }

    private function tenantId(mixed $tenant): ?int
    {
        if ($tenant === null || $tenant === '') {
            return null;
        }

        if (is_numeric($tenant)) {
            return (int) $tenant;
        }

        foreach (['tenant_id', 'organization_id', 'id'] as $key) {
            $value = data_get($tenant, $key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function urlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    private function trimText(string $text): string
    {
        return Str::limit($this->normalizeWhitespace($text), (int) config('source_extraction.max_text_chars', 120000), '');
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function estimateTokens(string $text): int
    {
        return (int) max(1, ceil(mb_strlen($text) / 4));
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function isTimeout(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28');
    }

    private function extractMarkdownTitle(string $text): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/^Title:\s*(.+)$/mi', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function jinaApiKey(): ?string
    {
        $key = trim((string) config('source_extraction.jina_api_key', ''));

        return $key !== '' ? $key : null;
    }

    private function dateOrNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function withMetadata(SourceExtractionResult $result, array $metadata): SourceExtractionResult
    {
        return new SourceExtractionResult(
            success: $result->success,
            url: $result->url,
            finalUrl: $result->finalUrl,
            title: $result->title,
            author: $result->author,
            publishedAt: $result->publishedAt,
            language: $result->language,
            summary: $result->summary,
            extractedText: $result->extractedText,
            html: $result->html,
            wordCount: $result->wordCount,
            chars: $result->chars,
            estimatedTokens: $result->estimatedTokens,
            method: $result->method,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            durationMs: $result->durationMs,
            metadata: array_merge($result->metadata, $metadata),
        );
    }

    private function messageForCode(string $code): string
    {
        return match ($code) {
            'SOURCE_URL_INVALID' => 'Enter a valid public article URL.',
            'SOURCE_FETCH_TIMEOUT' => 'We could not fetch this URL within the request window. We are trying fallback extraction methods.',
            'SOURCE_FETCH_BLOCKED' => 'This source site blocked automated extraction. We are trying fallback extraction methods.',
            'SOURCE_FETCH_EMPTY' => 'This URL returned an empty response. Try another public article URL.',
            'SOURCE_FETCH_UNAVAILABLE' => 'This source URL could not be reached. Check that the URL is public and try again.',
            'SOURCE_PAGE_TOO_LARGE' => 'This page is too large to analyze safely. Try a cleaner article URL or paste source notes manually.',
            'SOURCE_EXTRACTION_EMPTY' => 'We could not extract readable article content from this page.',
            'SOURCE_EXTRACTION_TOO_SHORT' => 'The extracted page content is too short to generate a reliable brief.',
            'SOURCE_EXTRACTION_UNSUPPORTED_STRUCTURE' => 'The page uses a structure we could not extract reliably.',
            'SOURCE_JINA_AUTH_REQUIRED' => 'Reader fallback requires a Jina API key. Configure SOURCE_EXTRACTION_JINA_API_KEY or disable Jina fallback.',
            'SOURCE_JINA_FAILED' => 'Reader fallback could not extract this source.',
            default => 'We could not extract this source automatically.',
        };
    }
}
