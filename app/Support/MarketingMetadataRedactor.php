<?php

namespace App\Support;

class MarketingMetadataRedactor
{
    private const REDACTED = '[redacted]';

    /**
     * @var array<int,string>
     */
    private const SECRET_KEY_PATTERNS = [
        '/access[_-]?token/i',
        '/refresh[_-]?token/i',
        '/id[_-]?token/i',
        '/token/i',
        '/secret/i',
        '/password/i',
        '/passwd/i',
        '/api[_-]?key/i',
        '/private[_-]?key/i',
        '/client[_-]?secret/i',
        '/authorization/i',
        '/auth[_-]?header/i',
        '/cookie/i',
        '/session/i',
    ];

    /**
     * @param  array<mixed>  $metadata
     * @return array<mixed>
     */
    public static function redact(array $metadata): array
    {
        return self::redactValue($metadata);
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T|array<mixed>|string
     */
    private static function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSecretKey($key)) {
            return self::REDACTED;
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $nestedKey => $nestedValue) {
            $redacted[$nestedKey] = self::redactValue(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null
            );
        }

        return $redacted;
    }

    private static function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
