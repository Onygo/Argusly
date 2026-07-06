<?php

namespace App\Services\PageIntelligence;

use InvalidArgumentException;

class PageUrlNormalizer
{
    /**
     * Tracking parameters do not identify durable page content.
     *
     * @var array<int,string>
     */
    private const TRACKING_PARAMETERS = [
        'fbclid',
        'gclid',
        'gclsrc',
        'igshid',
        'mc_cid',
        'mc_eid',
        'msclkid',
        'twclid',
        'yclid',
    ];

    public function normalize(string $url, ?string $canonicalUrl = null): PageUrlNormalizationResult
    {
        $firstSeen = $this->normalizeUrl($url, stripTrackingParameters: false);
        $canonical = trim((string) $canonicalUrl) !== ''
            ? $this->normalizeUrl((string) $canonicalUrl, stripTrackingParameters: true)
            : $this->normalizeUrl($url, stripTrackingParameters: true);

        if ($firstSeen['domain'] !== $canonical['domain'] && trim((string) $canonicalUrl) === '') {
            throw new InvalidArgumentException('The normalized URL produced an inconsistent canonical domain.');
        }

        return new PageUrlNormalizationResult(
            inputUrl: $url,
            firstSeenUrl: $firstSeen['url'],
            firstSeenUrlHash: hash('sha256', $firstSeen['url']),
            canonicalUrl: $canonical['url'],
            canonicalUrlHash: hash('sha256', $canonical['url']),
            scheme: $canonical['scheme'],
            domain: $canonical['domain'],
            path: $canonical['path'],
            hasCanonicalIdentity: trim((string) $canonical['url']) !== '',
        );
    }

    /**
     * @return array{url:string,scheme:string,domain:string,path:string}
     */
    private function normalizeUrl(string $url, bool $stripTrackingParameters): array
    {
        $candidate = trim($url);
        if ($candidate === '') {
            throw new InvalidArgumentException('Enter a valid public URL.');
        }

        if (str_contains($candidate, '://') && ! preg_match('#^https?://#i', $candidate)) {
            throw new InvalidArgumentException('Only http and https URLs can be submitted.');
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Enter a valid public URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs can be submitted.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = trim($host, '[]');
        if ($host === '') {
            throw new InvalidArgumentException('Enter a valid public URL with a host.');
        }

        if ($this->isBlockedHost($host)) {
            throw new InvalidArgumentException('This URL is not public and cannot be submitted.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = $this->normalizeQuery((string) ($parts['query'] ?? ''), $stripTrackingParameters);

        $normalized = $scheme . '://' . $host;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':' . $port;
        }

        $normalized .= $path;

        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        if (strlen($normalized) > 2048) {
            throw new InvalidArgumentException('This URL is too long to submit.');
        }

        return [
            'url' => $normalized,
            'scheme' => $scheme,
            'domain' => $host,
            'path' => $path,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = $this->decodeSafePathCharacters($path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                return '/';
            }
        }

        return $path;
    }

    private function normalizeQuery(string $query, bool $stripTrackingParameters): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $pairs = [];
        parse_str($query, $pairs);

        if ($stripTrackingParameters) {
            $pairs = collect($pairs)
                ->reject(fn (mixed $value, string|int $key): bool => $this->isTrackingParameter((string) $key))
                ->all();
        }

        if ($pairs === []) {
            return '';
        }

        ksort($pairs);

        return http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function isTrackingParameter(string $key): bool
    {
        $key = strtolower(trim($key));

        return str_starts_with($key, 'utm_')
            || str_starts_with($key, '_hs')
            || in_array($key, self::TRACKING_PARAMETERS, true);
    }

    private function decodeSafePathCharacters(string $path): string
    {
        return preg_replace_callback('/%[0-9a-fA-F]{2}/', static function (array $matches): string {
            $hex = strtoupper((string) $matches[0]);
            $char = chr(hexdec(substr($hex, 1)));

            if (preg_match('/[A-Za-z0-9\\-._~]/', $char) === 1) {
                return $char;
            }

            return $hex;
        }, $path) ?? $path;
    }

    private function isBlockedHost(string $host): bool
    {
        $normalized = strtolower(trim($host));
        $normalized = trim($normalized, '[]');

        if ($normalized === '' || in_array($normalized, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
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
}
