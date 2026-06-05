<?php

namespace App\Support;

/**
 * Sanitizes SEO description fields (meta_description, og_description, twitter_description).
 *
 * This class ensures description fields contain clean, usable text that:
 * - Stays within recommended SEO length limits
 * - Contains no HTML tags, markdown, or prompt artifacts
 * - Is properly formatted for display in search results and social shares
 */
class DescriptionSanitizer
{
    /**
     * Standard SEO limits based on best practices.
     * These are soft limits for SEO quality, not hard database limits.
     */
    public const META_DESCRIPTION_MAX = 160;
    public const OG_DESCRIPTION_MAX = 300;
    public const TWITTER_DESCRIPTION_MAX = 200;
    public const CANONICAL_URL_MAX = 2048;

    /**
     * Hard database limits to prevent SQL truncation errors.
     * TEXT columns in MySQL can hold ~65KB but we set practical limits.
     */
    public const DB_TEXT_SAFE_LIMIT = 10000;

    /**
     * Normalize a meta description for SEO.
     */
    public static function normalizeMetaDescription(
        mixed $description,
        ?string $fallback = null,
        int $maxLength = self::META_DESCRIPTION_MAX,
    ): string {
        return self::normalizeWithMetadata($description, $fallback, $maxLength)['description'];
    }

    /**
     * Normalize an Open Graph description.
     */
    public static function normalizeOgDescription(
        mixed $description,
        ?string $fallback = null,
        int $maxLength = self::OG_DESCRIPTION_MAX,
    ): string {
        return self::normalizeWithMetadata($description, $fallback, $maxLength)['description'];
    }

    /**
     * Normalize a Twitter description.
     */
    public static function normalizeTwitterDescription(
        mixed $description,
        ?string $fallback = null,
        int $maxLength = self::TWITTER_DESCRIPTION_MAX,
    ): string {
        return self::normalizeWithMetadata($description, $fallback, $maxLength)['description'];
    }

    /**
     * Normalize a canonical URL.
     */
    public static function normalizeCanonicalUrl(
        mixed $url,
        int $maxLength = self::CANONICAL_URL_MAX,
    ): ?string {
        if ($url === null) {
            return null;
        }

        $cleaned = self::toCleanString($url);

        if ($cleaned === '') {
            return null;
        }

        // Basic URL validation
        if (! filter_var($cleaned, FILTER_VALIDATE_URL)) {
            // Try to fix common issues
            if (! str_starts_with($cleaned, 'http://') && ! str_starts_with($cleaned, 'https://')) {
                $cleaned = 'https://' . ltrim($cleaned, '/');
            }
        }

        // Truncate if too long (should rarely happen with valid URLs)
        if (mb_strlen($cleaned) > $maxLength) {
            return mb_substr($cleaned, 0, $maxLength);
        }

        return $cleaned;
    }

    /**
     * Normalize with full metadata about the sanitization.
     *
     * @return array{
     *     description: string,
     *     original_value: string,
     *     was_sanitized: bool,
     *     was_truncated: bool,
     *     was_rejected: bool,
     *     rejection_reason: string|null,
     *     original_length: int,
     *     persisted_length: int,
     *     max_length: int
     * }
     */
    public static function normalizeWithMetadata(
        mixed $description,
        ?string $fallback = null,
        int $maxLength = self::META_DESCRIPTION_MAX,
    ): array {
        $maxLength = max(1, min($maxLength, self::DB_TEXT_SAFE_LIMIT));
        $original = self::toCleanString($description);
        $rejectionReason = null;
        $wasSanitized = false;
        $wasTruncated = false;
        $wasRejected = false;

        // Empty check
        if ($original === '') {
            $finalValue = $fallback !== null ? self::truncate($fallback, $maxLength) : '';

            return self::buildResult(
                description: $finalValue,
                original: '',
                wasSanitized: $fallback !== null && $fallback !== '',
                wasTruncated: false,
                wasRejected: false,
                rejectionReason: null,
                maxLength: $maxLength,
            );
        }

        // Check for obviously invalid content
        $rejectionReason = self::detectInvalidContent($original);
        if ($rejectionReason !== null) {
            $wasRejected = true;
            $wasSanitized = true;

            // Try to extract usable content
            $derived = self::deriveDescriptionFromText($original, $maxLength);
            if ($derived !== '') {
                return self::buildResult(
                    description: $derived,
                    original: $original,
                    wasSanitized: true,
                    wasTruncated: mb_strlen($derived) < mb_strlen($original),
                    wasRejected: true,
                    rejectionReason: $rejectionReason,
                    maxLength: $maxLength,
                );
            }

            // Use fallback
            $finalFallback = $fallback !== null ? self::truncate($fallback, $maxLength) : '';

            return self::buildResult(
                description: $finalFallback,
                original: $original,
                wasSanitized: true,
                wasTruncated: false,
                wasRejected: true,
                rejectionReason: $rejectionReason,
                maxLength: $maxLength,
            );
        }

        // Truncate if needed
        $result = $original;
        if (mb_strlen($result) > $maxLength) {
            $result = self::truncate($result, $maxLength);
            $wasTruncated = true;
            $wasSanitized = true;
        }

        return self::buildResult(
            description: $result,
            original: $original,
            wasSanitized: $wasSanitized,
            wasTruncated: $wasTruncated,
            wasRejected: $wasRejected,
            rejectionReason: $rejectionReason,
            maxLength: $maxLength,
        );
    }

    /**
     * Detect if the value is obviously not a valid description.
     */
    private static function detectInvalidContent(string $value): ?string
    {
        // JSON fragments
        if (self::looksLikeJson($value)) {
            return 'json_fragment';
        }

        // Prompt-like patterns
        if (self::looksLikePromptText($value)) {
            return 'prompt_text';
        }

        // Contains markdown code blocks
        if (preg_match('/```[\s\S]*```/', $value)) {
            return 'markdown_code_block';
        }

        // Contains HTML structure (not just simple tags)
        if (preg_match('/<(div|span|section|article|header|footer|nav|script|style)[^>]*>/i', $value)) {
            return 'html_structure';
        }

        // Contains URL-heavy content (likely auto-generated or scraped)
        if (preg_match_all('/https?:\/\/[^\s]+/', $value, $matches) && count($matches[0]) > 2) {
            return 'url_heavy_content';
        }

        return null;
    }

    /**
     * Check if the value looks like JSON.
     */
    private static function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);

        if (
            (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        ) {
            return true;
        }

        if (preg_match('/"[a-z_]+"\s*:\s*["{[\d]/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the value looks like prompt or instruction text.
     */
    private static function looksLikePromptText(string $value): bool
    {
        $lower = mb_strtolower($value);

        $promptPatterns = [
            'you are',
            'write a',
            'generate a',
            'create a',
            'please write',
            'must be',
            'should be',
            'do not',
            'task:',
            'instructions:',
            'system:',
            'user:',
            'assistant:',
            'here is the',
            'here are the',
            'below is',
        ];

        foreach ($promptPatterns as $pattern) {
            if (str_starts_with($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to derive a usable description from problematic text.
     */
    private static function deriveDescriptionFromText(string $text, int $maxLength): string
    {
        // Remove common artifacts
        $clean = preg_replace('/^(meta_description|description|seo_meta_description)[:\s]+/i', '', $text) ?? $text;
        $clean = preg_replace('/[{}\[\]"]+/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        // If it's still reasonable length, use it
        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        // Truncate intelligently
        return self::truncate($clean, $maxLength);
    }

    /**
     * Convert input to a clean string.
     */
    private static function toCleanString(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $str = (string) $value;

        // Decode HTML entities
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip HTML tags
        $str = strip_tags($str);

        // Normalize whitespace
        $str = preg_replace('/[\r\n\t]+/', ' ', $str) ?? $str;
        $str = preg_replace('/\s+/', ' ', $str) ?? $str;

        return trim($str);
    }

    /**
     * Truncate to max length, preferring sentence/word boundaries.
     */
    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $candidate = mb_substr($value, 0, $maxLength);

        // Try to end at a sentence boundary
        $lastSentenceEnd = max(
            mb_strrpos($candidate, '. ') ?: 0,
            mb_strrpos($candidate, '! ') ?: 0,
            mb_strrpos($candidate, '? ') ?: 0,
        );

        $minLength = (int) floor($maxLength * 0.5);

        if ($lastSentenceEnd >= $minLength) {
            return mb_substr($candidate, 0, $lastSentenceEnd + 1);
        }

        // Fall back to word boundary
        $lastSpace = mb_strrpos($candidate, ' ');
        $wordMinLength = (int) floor($maxLength * 0.7);

        if ($lastSpace !== false && $lastSpace >= $wordMinLength) {
            $candidate = mb_substr($candidate, 0, $lastSpace);
        }

        // Clean up trailing punctuation
        $candidate = preg_replace('/[\s\.,;:!\?\-\x{2013}\x{2014}]+$/u', '', $candidate) ?? $candidate;

        return trim($candidate);
    }

    /**
     * Build the result array.
     *
     * @return array{
     *     description: string,
     *     original_value: string,
     *     was_sanitized: bool,
     *     was_truncated: bool,
     *     was_rejected: bool,
     *     rejection_reason: string|null,
     *     original_length: int,
     *     persisted_length: int,
     *     max_length: int
     * }
     */
    private static function buildResult(
        string $description,
        string $original,
        bool $wasSanitized,
        bool $wasTruncated,
        bool $wasRejected,
        ?string $rejectionReason,
        int $maxLength,
    ): array {
        return [
            'description' => $description,
            'original_value' => $original,
            'was_sanitized' => $wasSanitized,
            'was_truncated' => $wasTruncated,
            'was_rejected' => $wasRejected,
            'rejection_reason' => $rejectionReason,
            'original_length' => mb_strlen($original),
            'persisted_length' => mb_strlen($description),
            'max_length' => $maxLength,
        ];
    }
}
