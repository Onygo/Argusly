<?php

namespace App\Services\Research;

use App\Enums\ResearchSourceFetchStatus;
use App\Enums\ResearchSourceType;
use App\Models\ResearchProject;
use App\Models\ResearchSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SourceIngestionService
{
    /**
     * @param array<int,string> $urls
     * @return Collection<int,ResearchSource>
     */
    public function syncSourcesFromUrls(ResearchProject $project, array $urls): Collection
    {
        $normalized = collect($urls)
            ->map(fn (mixed $value): ?string => $this->normalizeUrl((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return collect();
        }

        $existingByUrl = $project->sources()
            ->where('source_type', ResearchSourceType::URL->value)
            ->whereNotNull('url')
            ->get()
            ->keyBy(fn (ResearchSource $source): string => trim((string) $source->url));

        $created = collect();

        foreach ($normalized as $url) {
            $source = $existingByUrl->get($url);
            if ($source) {
                $created->push($source);

                continue;
            }

            $classification = $this->classifyUrl($url);

            $source = $project->sources()->create([
                'source_type' => ResearchSourceType::URL,
                'source_classification' => $classification,
                'url' => $url,
                'fetch_status' => ResearchSourceFetchStatus::PENDING,
                'meta' => [
                    'ingestion' => [
                        'normalized_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            $created->push($source);
        }

        return $created;
    }

    public function fetchSource(ResearchSource $source): ResearchSource
    {
        $source->loadMissing('project');

        if (
            (string) ($source->fetch_status?->value ?? $source->fetch_status) === ResearchSourceFetchStatus::FETCHED->value
            && trim((string) ($source->content_text ?? '')) !== ''
        ) {
            return $source;
        }

        $meta = is_array($source->meta) ? $source->meta : [];

        $source->update([
            'fetch_status' => ResearchSourceFetchStatus::FETCHING,
            'meta' => array_replace_recursive($meta, [
                'fetch' => [
                    'status' => ResearchSourceFetchStatus::FETCHING->value,
                    'started_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        try {
            $source->refresh();

            return $this->fetchBySourceType($source);
        } catch (Throwable $exception) {
            $mergedMeta = array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                'fetch' => [
                    'status' => ResearchSourceFetchStatus::FAILED->value,
                    'failed_at' => now()->toIso8601String(),
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ],
            ]);

            $source->update([
                'fetch_status' => ResearchSourceFetchStatus::FAILED,
                'meta' => $mergedMeta,
            ]);

            return $source->fresh();
        }
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

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        $query = isset($parts['query']) && trim((string) $parts['query']) !== ''
            ? '?' . $parts['query']
            : '';

        $port = isset($parts['port'])
            && ! (($scheme === 'https' && (int) $parts['port'] === 443) || ($scheme === 'http' && (int) $parts['port'] === 80))
            ? ':' . (int) $parts['port']
            : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function fetchBySourceType(ResearchSource $source): ResearchSource
    {
        $classification = strtolower(trim((string) ($source->source_classification ?? 'web')));

        return match ($classification) {
            'pdf', 'document' => $this->markDocumentUnsupported($source),
            default => $this->fetchUrlSource($source),
        };
    }

    private function fetchUrlSource(ResearchSource $source): ResearchSource
    {
        $url = trim((string) ($source->url ?? ''));
        if ($url === '') {
            return $this->markFetchFailed($source, 'Source URL is missing.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($this->isBlockedHost($host)) {
            return $this->markFetchFailed($source, 'URL host is blocked for safety.', ['blocked_host' => $host]);
        }

        $timeout = max(5, (int) config('research.source_fetch.timeout_seconds', 20));

        $response = Http::timeout($timeout)
            ->retry(2, 300)
            ->accept('text/html, text/plain, application/xhtml+xml;q=0.9, application/xml;q=0.8, */*;q=0.5')
            ->get($url);

        if ($response->failed()) {
            return $this->markFetchFailed($source, 'HTTP fetch failed with status ' . $response->status(), [
                'http_status' => $response->status(),
            ]);
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
        if ($contentType !== '' && ! $this->isTextLikeContentType($contentType)) {
            return $this->markFetchFailed($source, 'Unsupported content type.', [
                'content_type' => $contentType,
            ]);
        }

        $rawBody = (string) $response->body();
        $title = $this->extractTitle($rawBody);
        $text = $this->toPlainText($rawBody);

        $maxChars = max(5000, (int) config('research.source_fetch.max_content_chars', 60000));
        $text = mb_substr($text, 0, $maxChars);

        if (trim($text) === '') {
            return $this->markFetchFailed($source, 'Source text is empty after normalization.', [
                'http_status' => $response->status(),
                'content_type' => $contentType,
            ]);
        }

        $meta = array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
            'fetch' => [
                'status' => ResearchSourceFetchStatus::FETCHED->value,
                'fetched_at' => now()->toIso8601String(),
                'http_status' => $response->status(),
                'content_type' => $contentType,
                'final_url' => (string) $response->effectiveUri(),
                'content_length' => mb_strlen($text),
            ],
            'extraction' => [
                'status' => 'pending',
            ],
        ]);

        $source->update([
            'title' => $title ?: $source->title,
            'content_text' => $text,
            'fetch_status' => ResearchSourceFetchStatus::FETCHED,
            'fetched_at' => now(),
            'meta' => $meta,
        ]);

        return $source->fresh();
    }

    private function markDocumentUnsupported(ResearchSource $source): ResearchSource
    {
        $meta = array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
            'fetch' => [
                'status' => ResearchSourceFetchStatus::SKIPPED->value,
                'failed_at' => now()->toIso8601String(),
                'error' => 'Document ingestion is not enabled yet. URL kept for future processing.',
            ],
        ]);

        $source->update([
            'fetch_status' => ResearchSourceFetchStatus::SKIPPED,
            'meta' => $meta,
        ]);

        return $source->fresh();
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function markFetchFailed(ResearchSource $source, string $reason, array $extra = []): ResearchSource
    {
        $meta = array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
            'fetch' => array_merge([
                'status' => ResearchSourceFetchStatus::FAILED->value,
                'failed_at' => now()->toIso8601String(),
                'error' => mb_substr($reason, 0, 1000),
            ], $extra),
        ]);

        $source->update([
            'fetch_status' => ResearchSourceFetchStatus::FAILED,
            'meta' => $meta,
        ]);

        return $source->fresh();
    }

    private function classifyUrl(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (preg_match('/\.(pdf|doc|docx|ppt|pptx|xls|xlsx)$/', $path)) {
            return 'document';
        }

        return 'web';
    }

    private function isTextLikeContentType(string $contentType): bool
    {
        return Str::contains($contentType, [
            'text/html',
            'text/plain',
            'application/xhtml+xml',
            'application/xml',
            'application/json',
        ]);
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || Str::endsWith($host, ['.local', '.internal', '.test'])) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        return false;
    }

    private function extractTitle(string $raw): ?string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/is', $raw, $matches)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags((string) ($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title !== '' ? mb_substr($title, 0, 500) : null;
    }

    private function toPlainText(string $raw): string
    {
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $raw) ?? $raw;
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $clean) ?? $clean;
        $clean = strip_tags($clean);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }
}
