<?php

namespace App\Support;

class EnglishTranslationNormalizer
{
    private const DUTCH_VAN_TO_ENGLISH_FROM_PATTERN = '/^(\s*["\']?)Van\s+(?=(?:(?:AI|SEO|GEO|LLM|API|CRM|CMS|B2B|B2C|ROI)\b|[^\n:]{1,180}\bto\b))/u';

    public static function normalizeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return collect(explode("\n", $text))
            ->map(fn (string $line): string => self::normalizeLine($line))
            ->implode("\n");
    }

    public static function normalizeLine(string $line): string
    {
        return preg_replace(self::DUTCH_VAN_TO_ENGLISH_FROM_PATTERN, '$1From ', $line) ?? $line;
    }
}
