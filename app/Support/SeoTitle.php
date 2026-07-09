<?php

namespace App\Support;

class SeoTitle
{
    public const MAX_LENGTH = 70;

    public static function normalize(mixed $title, int $maxLength = self::MAX_LENGTH): string
    {
        $maxLength = max(1, $maxLength);
        $title = TitleSanitizer::normalize($title, maxLength: 255, fallback: 'Argusly');

        if (mb_strlen($title) <= $maxLength) {
            return $title;
        }

        $parts = preg_split('/\s+\|\s+/u', $title) ?: [];
        $suffix = trim((string) array_pop($parts));
        $base = trim(implode(' | ', $parts));

        if ($base !== '' && $suffix !== '') {
            $suffixWithSeparator = ' | '.$suffix;
            $baseMaxLength = $maxLength - mb_strlen($suffixWithSeparator);

            if ($baseMaxLength >= 20) {
                $shortBase = TitleSanitizer::normalize($base, maxLength: $baseMaxLength, fallback: $base);
                $candidate = trim($shortBase.$suffixWithSeparator);

                if ($candidate !== '' && mb_strlen($candidate) <= $maxLength) {
                    return $candidate;
                }
            }
        }

        return TitleSanitizer::normalize($title, maxLength: $maxLength, fallback: 'Argusly');
    }

    public static function withSuffix(mixed $title, string $suffix, int $maxLength = self::MAX_LENGTH): string
    {
        $base = TitleSanitizer::normalize($title, maxLength: 255, fallback: 'Argusly');
        $suffix = TitleSanitizer::normalize($suffix, maxLength: 80, fallback: 'Argusly');

        if (str_contains($base, 'Argusly')) {
            return self::normalize($base, $maxLength);
        }

        $withSuffix = $base.' | '.$suffix;
        if (mb_strlen($withSuffix) <= $maxLength) {
            return $withSuffix;
        }

        if (mb_strlen($base) <= $maxLength) {
            return $base;
        }

        return self::normalize($base, $maxLength);
    }
}
