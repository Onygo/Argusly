<?php

namespace App\Services\SourceBriefing;

use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlSourceFetcher
{
    private const MAX_RESPONSE_BYTES = 4_000_000;

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $url, array $context = []): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            throw new SourceBriefingException(
                'SOURCE_URL_INVALID',
                'Enter a valid public article URL.',
            );
        }

        $host = (string) parse_url($normalized, PHP_URL_HOST);
        if ($this->isBlockedHost($host)) {
            throw new SourceBriefingException(
                'SOURCE_FETCH_BLOCKED',
                'This URL cannot be analyzed. Use a public article URL.',
                'Blocked host: ' . $host,
            );
        }

        $startedAt = microtime(true);
        Log::info('source_briefing.url_fetch_start', array_merge($context, [
            'url' => $normalized,
            'mode' => $context['mode'] ?? null,
        ]));

        try {
            $response = null;
            foreach ([1, 2, 3] as $attempt) {
                $response = Http::timeout(20)
                    ->connectTimeout(4)
                    ->retry(1, 250)
                    ->accept('text/html, application/xhtml+xml, text/plain;q=0.9, */*;q=0.1')
                    ->withHeaders([
                        'User-Agent' => 'PublishLayer/SourceBriefing',
                    ])
                    ->get($normalized);

                if (! in_array($response->status(), [429, 503], true) || $attempt === 3) {
                    break;
                }

                usleep($attempt * 500_000);
            }
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            throw new SourceBriefingException(
                str_contains($message, 'timeout') || str_contains($message, 'timed out')
                    ? 'SOURCE_FETCH_TIMEOUT'
                    : 'SOURCE_FETCH_FAILED',
                str_contains($message, 'timeout') || str_contains($message, 'timed out')
                    ? 'We could not fetch this URL within 20 seconds. The source site may be slow or blocking automated requests.'
                    : 'We could not fetch this URL. Check that it is public and try again.',
                'Failed to fetch URL: ' . $exception->getMessage(),
                retryable: true,
                previous: $exception,
            );
        }

        $contentLength = (int) ($response->header('Content-Length') ?: 0);
        Log::info('source_briefing.url_fetch_end', array_merge($context, [
            'url' => $normalized,
            'final_url' => (string) ($response->effectiveUri() ?: $normalized),
            'http_status' => $response->status(),
            'content_type' => strtolower(trim((string) $response->header('Content-Type', ''))),
            'content_length' => $contentLength,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]));

        if ($response->failed()) {
            $status = $response->status();
            throw new SourceBriefingException(
                in_array($status, [401, 403, 429], true) ? 'SOURCE_FETCH_BLOCKED' : 'SOURCE_FETCH_UNAVAILABLE',
                in_array($status, [401, 403, 429], true)
                    ? 'This source site blocked the fetch request. Use another public URL or create the brief manually.'
                    : 'This source URL could not be reached. Check that the URL is public and try again.',
                'Failed to fetch URL: HTTP ' . $status,
                retryable: in_array($status, [429, 503, 504], true),
            );
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
        if ($contentType !== '' && ! $this->isSupportedContentType($contentType)) {
            throw new SourceBriefingException(
                'SOURCE_CONTENT_UNSUPPORTED',
                'This URL returned an unsupported content type. Use a public article page.',
                'Unsupported content type: ' . $contentType,
            );
        }

        $html = (string) $response->body();
        if (trim($html) === '') {
            throw new SourceBriefingException(
                'SOURCE_FETCH_EMPTY',
                'This URL returned an empty response. Try another public article URL.',
            );
        }

        $bodyLength = strlen($html);
        if ($bodyLength > self::MAX_RESPONSE_BYTES) {
            throw new SourceBriefingException(
                'SOURCE_PAGE_TOO_LARGE',
                'This page is too large to analyze safely. Try a cleaner article URL or paste the key source details manually.',
                'Fetched page exceeded max response bytes: ' . $bodyLength,
            );
        }

        return [
            'normalized_url' => $normalized,
            'final_url' => (string) ($response->effectiveUri() ?: $normalized),
            'status_code' => $response->status(),
            'content_type' => $contentType,
            'content_length' => $bodyLength,
            'html' => $html,
            'headers' => [
                'content_type' => $contentType,
            ],
        ];
    }

    public function normalizeUrl(string $url): ?string
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
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';
        $query = trim((string) ($parts['query'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port . $path . ($query !== '' ? '?' . $query : '');
    }

    private function isBlockedHost(string $host): bool
    {
        $normalized = strtolower(trim($host));
        if ($normalized === '' || in_array($normalized, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (str_ends_with($normalized, '.local') || str_ends_with($normalized, '.internal')) {
            return true;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return ! filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    private function isSupportedContentType(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml')
            || str_contains($contentType, 'text/plain');
    }
}
