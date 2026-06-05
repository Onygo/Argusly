<?php

namespace App\Support;

class SiteUrl
{
    public static function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            return rtrim(strtolower($value), '/');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '';

        return rtrim($scheme . '://' . $host . $path, '/');
    }

    public static function hostFromUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        return strtolower((string) (parse_url($value, PHP_URL_HOST) ?? ''));
    }
}
