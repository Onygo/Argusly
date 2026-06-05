<?php

namespace App\Support;

class FailedJobPayloadRedactor
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function redact(array $payload): array
    {
        return self::redactRecursive($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function redactRecursive(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $isSecretKey = self::isSecretKey($normalizedKey);

            if (is_array($value)) {
                $redacted[$key] = self::redactRecursive($value);
                continue;
            }

            if ($isSecretKey) {
                $redacted[$key] = self::maskValue($value);
                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = self::sanitizeString($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private static function isSecretKey(string $key): bool
    {
        foreach ([
            'password',
            'secret',
            'token',
            'api_key',
            'apikey',
            'authorization',
            'cookie',
            'signature',
            'webhook_secret',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function maskValue(mixed $value): string
    {
        if (is_string($value) && str_starts_with(strtolower($value), 'bearer ')) {
            return 'Bearer [REDACTED]';
        }

        return '[REDACTED]';
    }

    private static function sanitizeString(string $value): string
    {
        $patterns = [
            '/(api[_-]?key|token|secret|password|authorization|cookie|signature|webhook_secret)\s*[:=]\s*([^\s"\']+)/i',
            '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }
}
