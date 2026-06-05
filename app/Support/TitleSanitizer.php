<?php

namespace App\Support;

class TitleSanitizer
{
    public const MAX_LENGTH = 255;
    public const FALLBACK_TITLE = 'Untitled content';

    /**
     * @return array{title:string,original_title:string,was_shortened:bool,original_length:int,persisted_length:int,max_length:int}
     */
    public static function normalizeWithMetadata(
        mixed $title,
        int $maxLength = self::MAX_LENGTH,
        string $fallback = self::FALLBACK_TITLE,
        bool $allowEmpty = false,
    ): array {
        $original = self::normalizeWhitespace((string) $title);

        if ($original === '' && $allowEmpty) {
            return [
                'title' => '',
                'original_title' => '',
                'was_shortened' => false,
                'original_length' => 0,
                'persisted_length' => 0,
                'max_length' => $maxLength,
            ];
        }

        $candidate = $original !== '' ? $original : self::normalizeWhitespace($fallback);
        $candidate = $candidate !== '' ? $candidate : self::FALLBACK_TITLE;

        $normalized = self::shorten($candidate, $maxLength);
        if ($normalized === '') {
            $normalized = self::shorten(self::FALLBACK_TITLE, $maxLength);
        }

        return [
            'title' => $normalized,
            'original_title' => $original,
            'was_shortened' => mb_strlen($candidate) > mb_strlen($normalized),
            'original_length' => mb_strlen($candidate),
            'persisted_length' => mb_strlen($normalized),
            'max_length' => $maxLength,
        ];
    }

    public static function normalize(
        mixed $title,
        int $maxLength = self::MAX_LENGTH,
        string $fallback = self::FALLBACK_TITLE,
        bool $allowEmpty = false,
    ): string {
        return self::normalizeWithMetadata($title, $maxLength, $fallback, $allowEmpty)['title'];
    }

    private static function normalizeWhitespace(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function shorten(string $value, int $maxLength): string
    {
        $maxLength = max(1, $maxLength);

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $candidate = mb_substr($value, 0, $maxLength);
        $lastSpace = mb_strrpos($candidate, ' ');
        $minimumReadableLength = min(80, (int) floor($maxLength * 0.6));

        if ($lastSpace !== false && $lastSpace >= $minimumReadableLength) {
            $candidate = mb_substr($candidate, 0, $lastSpace);
        }

        $candidate = preg_replace('/[\s\.,;:!\?\-\x{2013}\x{2014}\(\[\{]+$/u', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);

        if ($candidate === '') {
            $candidate = trim(mb_substr($value, 0, $maxLength));
        }

        return mb_substr($candidate, 0, $maxLength);
    }
}
