<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FeedItemNormalizer
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $url = $this->normalizeUrl($payload['url'] ?? null);
        $title = $this->stringOrNull($payload['title'] ?? null);
        $summary = $this->stringOrNull($payload['summary'] ?? null);
        $body = $this->stringOrNull($payload['body'] ?? null);
        $externalId = $this->stringOrNull($payload['external_id'] ?? null);
        $publishedAt = $this->parseDate($payload['published_at'] ?? null);
        $rawPayload = (array) ($payload['raw_payload'] ?? $payload);

        return [
            'external_id' => $externalId,
            'url' => $url,
            'url_hash' => $url ? hash('sha256', $url) : null,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'author' => $this->stringOrNull($payload['author'] ?? null),
            'published_at' => $publishedAt,
            'fetched_at' => $this->parseDate($payload['fetched_at'] ?? null) ?? now(),
            'language' => $this->stringOrNull($payload['language'] ?? null),
            'raw_payload' => $rawPayload,
            'content_hash' => $this->contentHash($externalId, $url, $title, $summary, $body, $publishedAt?->toIso8601String()),
            'processing_status' => SignalStatus::NEW->value,
            'processing_error' => null,
        ];
    }

    private function normalizeUrl(mixed $url): ?string
    {
        $value = trim((string) $url);

        if ($value === '') {
            return null;
        }

        if (! Str::startsWith(Str::lower($value), ['http://', 'https://'])) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || empty($parts['host'])) {
            return $value;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.($path === '' ? '' : $path).$query;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        try {
            return Carbon::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function contentHash(?string ...$parts): string
    {
        $content = collect($parts)
            ->map(fn (?string $part): string => trim((string) $part))
            ->filter()
            ->implode('|');

        return hash('sha256', $content !== '' ? $content : Str::uuid()->toString());
    }
}
