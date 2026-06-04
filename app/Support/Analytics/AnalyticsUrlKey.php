<?php

namespace App\Support\Analytics;

class AnalyticsUrlKey
{
    public static function normalizeUrl(string $url): ?string
    {
        $parts = self::parse($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = self::normalizePathComponent((string) ($parts['path'] ?? '/'));
        $normalized = $scheme.'://'.$host;

        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':'.$port;
        }

        $normalized .= $path;

        return strlen($normalized) > 2000 ? substr($normalized, 0, 2000) : $normalized;
    }

    public static function fromUrl(string $url): string
    {
        $host = self::hostFromUrl($url);
        $path = self::pathFromUrl($url);

        if ($host === '' || $path === '') {
            return '';
        }

        $key = $host.$path;

        return strlen($key) > 512 ? substr($key, 0, 512) : $key;
    }

    public static function hostFromUrl(string $url): string
    {
        $parts = self::parse($url);

        return is_array($parts) && ! empty($parts['host'])
            ? strtolower((string) $parts['host'])
            : '';
    }

    public static function pathFromUrl(string $url): string
    {
        $parts = self::parse($url);
        if (! is_array($parts)) {
            return '';
        }

        return self::normalizePathComponent((string) ($parts['path'] ?? '/'));
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (! str_contains($url, '://') && ! str_starts_with($url, '/')) {
            $url = 'https://'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        return is_array($parts) ? $parts : null;
    }

    private static function normalizePathComponent(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = explode('#', $path, 2)[0];
        $path = explode('?', $path, 2)[0];
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = strtolower($path);

        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        return $path;
    }
}
