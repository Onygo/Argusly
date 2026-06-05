<?php

namespace App\Services\CompetitorIntelligence;

use Illuminate\Support\Str;

class CompetitorContentNormalizer
{
    public function normalize(array $payload): array
    {
        $url = $this->normalizeUrl((string) ($payload['url'] ?? ''));
        $title = $this->clean((string) ($payload['title'] ?? ''));
        $meta = $this->clean((string) ($payload['meta_description'] ?? ''));
        $body = $this->clean((string) ($payload['content_excerpt'] ?? $payload['content'] ?? ''));
        $text = trim(implode(' ', array_filter([$title, $meta, $body])));

        $normalizedText = Str::limit($text, 12000, '');
        $normalized = [
            'url' => $url,
            'url_hash' => hash('sha256', $url !== '' ? $url : hash('sha256', $title . '|' . $normalizedText)),
            'title' => $title !== '' ? $title : null,
            'meta_description' => $meta !== '' ? $meta : null,
            'content_excerpt' => $body !== '' ? Str::limit($body, 12000, '') : null,
            'normalized_text' => $normalizedText !== '' ? $normalizedText : null,
        ];

        $normalized['normalized_payload_hash'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $normalized;
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';

        return trim($value);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return strtolower($url);
        }

        $host = strtolower((string) $parts['host']);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return 'https://' . $host . ($path !== '' ? $path : '/') . $query;
    }
}
