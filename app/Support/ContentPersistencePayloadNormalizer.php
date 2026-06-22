<?php

namespace App\Support;

use App\Enums\ContentSource;
use App\Enums\SupportedLanguage;
use Illuminate\Support\Str;

class ContentPersistencePayloadNormalizer
{
    public const EXTERNAL_KEY_MAX_LENGTH = 255;
    public const URL_KEY_MAX_LENGTH = 512;
    public const BRIEF_SOURCE_MAX_LENGTH = 100;
    public const BRIEF_AUDIENCE_MAX_LENGTH = 255;
    public const BRIEF_SHORT_TEXT_MAX_LENGTH = 255;
    public const BRIEF_FUNNEL_STAGE_MAX_LENGTH = 32;
    public const BRIEF_SEARCH_INTENT_MAX_LENGTH = 32;
    public const BRIEF_CONTENT_TYPE_MAX_LENGTH = 32;

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function normalize(array $payload): array
    {
        $locale = array_key_exists('language', $payload)
            ? self::normalizeLocale($payload['language'])
            : null;

        if (array_key_exists('source', $payload)) {
            $payload['source'] = self::normalizeSource($payload['source'])->value;
        }

        if (array_key_exists('title', $payload)) {
            $payload['title'] = self::normalizeTitleForLocale($payload['title'], $locale);
        }

        foreach (['seo_title', 'seo_h1', 'seo_og_title', 'seo_twitter_title'] as $titleKey) {
            if (array_key_exists($titleKey, $payload) && $payload[$titleKey] !== null) {
                $payload[$titleKey] = self::normalizeTitleForLocale($payload[$titleKey], $locale);
            }
        }

        foreach (['seo_meta_description', 'seo_og_description', 'seo_twitter_description', 'public_blog_excerpt', 'excerpt'] as $textKey) {
            if (array_key_exists($textKey, $payload) && $payload[$textKey] !== null) {
                $payload[$textKey] = self::normalizeTextForLocale(self::collapseWhitespace($payload[$textKey]), $locale);
            }
        }

        if (array_key_exists('external_key', $payload)) {
            $payload['external_key'] = self::nullableString($payload['external_key'], self::EXTERNAL_KEY_MAX_LENGTH);
        }

        foreach (['publish_url_key', 'canonical_url_key'] as $urlKey) {
            if (array_key_exists($urlKey, $payload)) {
                $payload[$urlKey] = self::nullableString($payload[$urlKey], self::URL_KEY_MAX_LENGTH);
            }
        }

        if (array_key_exists('language', $payload)) {
            $payload['language'] = $locale;
        }

        if (array_key_exists('translation_source_locale', $payload)) {
            $payload['translation_source_locale'] = self::normalizeNullableLocale($payload['translation_source_locale']);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function normalizeBrief(array $payload): array
    {
        $locale = array_key_exists('language', $payload)
            ? self::normalizeLocale($payload['language'])
            : null;

        if (array_key_exists('source', $payload)) {
            $payload['source'] = self::nullableShortString($payload['source'], self::BRIEF_SOURCE_MAX_LENGTH);
        }

        if (array_key_exists('title', $payload)) {
            $payload['title'] = self::normalizeTitleForLocale($payload['title'], $locale, 'Untitled brief');
        }

        if (array_key_exists('language', $payload)) {
            $payload['language'] = $locale;
        }

        if (array_key_exists('primary_keyword', $payload)) {
            $payload['primary_keyword'] = KeywordSanitizer::normalize($payload['primary_keyword']);
        }

        foreach (['intent', 'output_type'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = self::nullableShortString($payload[$key], self::BRIEF_SHORT_TEXT_MAX_LENGTH);
            }
        }

        if (array_key_exists('content_type', $payload)) {
            $payload['content_type'] = self::nullableShortString($payload['content_type'], self::BRIEF_CONTENT_TYPE_MAX_LENGTH);
        }

        if (array_key_exists('funnel_stage', $payload)) {
            $payload['funnel_stage'] = self::nullableShortString($payload['funnel_stage'], self::BRIEF_FUNNEL_STAGE_MAX_LENGTH);
        }

        if (array_key_exists('search_intent', $payload)) {
            $payload['search_intent'] = self::nullableShortString($payload['search_intent'], self::BRIEF_SEARCH_INTENT_MAX_LENGTH);
        }

        if (array_key_exists('audience', $payload)) {
            $audience = self::normalizeBriefAudience(
                $payload['audience'],
                $payload['audience_details'] ?? null
            );

            $payload['audience'] = $audience['audience'];
            if ($audience['audience_details'] !== null || array_key_exists('audience_details', $payload)) {
                $payload['audience_details'] = $audience['audience_details'];
            }
        } elseif (array_key_exists('audience_details', $payload)) {
            $payload['audience_details'] = self::nullableLongText($payload['audience_details']);
        }

        foreach (['target_audience', 'notes', 'tone_of_voice', 'unique_angle', 'call_to_action'] as $longTextKey) {
            if (array_key_exists($longTextKey, $payload)) {
                $payload[$longTextKey] = self::nullableLongText($payload[$longTextKey]);
            }
        }

        return $payload;
    }

    public static function normalizeSource(mixed $value): ContentSource
    {
        if ($value instanceof ContentSource) {
            return $value;
        }

        return ContentSource::normalize((string) $value);
    }

    public static function normalizeTitle(mixed $value, string $fallback = 'Untitled'): string
    {
        return TitleSanitizer::normalize($value, fallback: $fallback);
    }

    public static function normalizeTitleForLocale(mixed $value, ?string $locale, string $fallback = 'Untitled'): string
    {
        $title = self::normalizeTitle($value, $fallback);

        return self::normalizeTextForLocale($title, $locale);
    }

    public static function normalizeTextForLocale(string $text, ?string $locale): string
    {
        return $locale === SupportedLanguage::NL->value
            ? DutchTextCasingNormalizer::normalizeText($text)
            : $text;
    }

    public static function normalizeLocale(mixed $value): string
    {
        $locale = SupportedLanguage::tryFromString((string) $value)?->value;

        return $locale ?? SupportedLanguage::EN->value;
    }

    public static function normalizeNullableLocale(mixed $value): ?string
    {
        $locale = SupportedLanguage::tryFromString((string) $value)?->value;

        return $locale !== null ? $locale : null;
    }

    private static function nullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = self::collapseWhitespace($value);

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    /**
     * @return array{audience:?string,audience_details:?string}
     */
    public static function normalizeBriefAudience(mixed $value, mixed $details = null): array
    {
        $normalized = self::collapseWhitespace(self::stringifyScalarList($value));
        $normalizedDetails = self::nullableLongText($details);

        if ($normalized === '') {
            return [
                'audience' => null,
                'audience_details' => $normalizedDetails,
            ];
        }

        if (mb_strlen($normalized) <= self::BRIEF_AUDIENCE_MAX_LENGTH) {
            return [
                'audience' => $normalized,
                'audience_details' => $normalizedDetails,
            ];
        }

        return [
            'audience' => mb_substr($normalized, 0, self::BRIEF_AUDIENCE_MAX_LENGTH),
            'audience_details' => $normalizedDetails ?: $normalized,
        ];
    }

    private static function nullableShortString(mixed $value, int $maxLength): ?string
    {
        $normalized = self::collapseWhitespace(self::stringifyScalarList($value));

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    private static function nullableLongText(mixed $value): ?string
    {
        $normalized = self::collapseWhitespace(self::stringifyScalarList($value));

        return $normalized !== '' ? $normalized : null;
    }

    private static function stringifyScalarList(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn (mixed $item): string => trim((string) $item))
                ->filter()
                ->implode(', ');
        }

        return (string) $value;
    }

    private static function collapseWhitespace(mixed $value): string
    {
        $string = (string) $value;

        return trim(preg_replace('/\s+/u', ' ', $string) ?? $string);
    }
}
