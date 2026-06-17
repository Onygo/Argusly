<?php

namespace App\Support;

use Illuminate\Support\Str;

class PublicErrorMessageSanitizer
{
    public static function sanitize(?string $message, string $fallback = 'The operation failed. Please retry or contact support if it keeps happening.'): ?string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return null;
        }

        $redacted = self::redact($message);

        if (self::containsSensitiveInfo($message) || self::containsSensitiveInfo($redacted)) {
            return $fallback;
        }

        return Str::limit($redacted, 280, '...');
    }

    public static function redact(string $message): string
    {
        $patterns = [
            '/(api[_-]?key|token|secret|password|authorization|cookie|signature|webhook_secret)\s*[:=]\s*([^\s"\']+)/i',
            '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i',
            '/\b(sk-(?:proj|ant|live|test)?-[A-Za-z0-9_\-]{12,})\b/i',
        ];

        foreach ($patterns as $pattern) {
            $message = preg_replace($pattern, '$1[REDACTED]', $message) ?? $message;
        }

        return $message;
    }

    private static function containsSensitiveInfo(string $message): bool
    {
        $messageLower = strtolower($message);

        foreach ([
            'sqlstate',
            'mysql',
            'pgsql',
            'postgresql',
            'query exception',
            'pdoexception',
            'pdo exception',
            'stack trace',
            'vendor/',
            'artisan',
            'illuminate\\',
            'laravel',
            '.php:',
            '->',
            '::',
            '/users/',
            '/var/www',
            '/var/folders',
            '/private/',
            '/home/',
            '/app/',
            'c:\\',
            'access denied',
            'authentication failed',
            'connection refused',
            'deadlock',
            'lock wait',
            'table_name',
            'column_name',
        ] as $pattern) {
            if (str_contains($messageLower, $pattern)) {
                return true;
            }
        }

        return preg_match('/\b(api[_-]?key|token|secret|password|authorization|cookie|signature)\b/i', $message) === 1;
    }
}
