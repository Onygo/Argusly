<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Sanitizes primary_keyword and related keyword values.
 *
 * Unlike titles, keywords should be short keyphrases (2-6 words typically).
 * This class detects and normalizes problematic values like:
 * - Sentences, paragraphs, or prompt text accidentally mapped
 * - Serialized JSON fragments
 * - Values exceeding database column limits
 */
class KeywordSanitizer
{
    public const MAX_LENGTH = 255;
    public const IDEAL_MAX_LENGTH = 60;
    public const MAX_WORD_COUNT = 8;

    /**
     * Normalize a keyword/keyphrase value.
     *
     * @param mixed $keyword The raw keyword value
     * @param string|null $fallback Fallback value if keyword is invalid
     * @param int $maxLength Maximum length (default 255 for DB column)
     * @return string The normalized keyword
     */
    public static function normalize(
        mixed $keyword,
        ?string $fallback = null,
        int $maxLength = self::MAX_LENGTH,
    ): string {
        return self::normalizeWithMetadata($keyword, $fallback, $maxLength)['keyword'];
    }

    /**
     * Normalize with metadata about what happened.
     *
     * @param mixed $keyword The raw keyword value
     * @param string|null $fallback Fallback value if keyword is invalid
     * @param int $maxLength Maximum length (default 255 for DB column)
     * @return array{
     *     keyword: string,
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
        mixed $keyword,
        ?string $fallback = null,
        int $maxLength = self::MAX_LENGTH,
    ): array {
        $maxLength = max(1, $maxLength);
        $original = self::toCleanString($keyword);
        $rejectionReason = null;
        $wasSanitized = false;
        $wasTruncated = false;
        $wasRejected = false;

        // Empty check
        if ($original === '') {
            return self::buildResult(
                keyword: $fallback !== null ? self::truncate($fallback, $maxLength) : '',
                original: '',
                wasSanitized: false,
                wasTruncated: false,
                wasRejected: false,
                rejectionReason: null,
                maxLength: $maxLength,
            );
        }

        // Preserve the original value (with newlines converted for logging purposes)
        $originalForLogging = self::finalNormalize($original);

        // Check for obviously invalid content
        $rejectionReason = self::detectInvalidContent($original);
        if ($rejectionReason !== null) {
            $wasRejected = true;
            $wasSanitized = true;

            // Try to extract a usable keyphrase
            $derived = self::deriveKeywordFromText($original, $maxLength);
            if ($derived !== '') {
                return self::buildResult(
                    keyword: self::finalNormalize($derived),
                    original: $originalForLogging,
                    wasSanitized: true,
                    wasTruncated: false,
                    wasRejected: true,
                    rejectionReason: $rejectionReason,
                    maxLength: $maxLength,
                );
            }

            // Fall back to provided fallback or empty
            $finalFallback = $fallback !== null ? self::truncate($fallback, $maxLength) : '';

            return self::buildResult(
                keyword: self::finalNormalize($finalFallback),
                original: $originalForLogging,
                wasSanitized: true,
                wasTruncated: false,
                wasRejected: true,
                rejectionReason: $rejectionReason,
                maxLength: $maxLength,
            );
        }

        // Final normalize and truncate if needed
        $result = self::finalNormalize($original);
        if (mb_strlen($result) > $maxLength) {
            $result = self::truncate($result, $maxLength);
            $wasTruncated = true;
            $wasSanitized = true;
        }

        return self::buildResult(
            keyword: $result,
            original: $original,
            wasSanitized: $wasSanitized,
            wasTruncated: $wasTruncated,
            wasRejected: $wasRejected,
            rejectionReason: $rejectionReason,
            maxLength: $maxLength,
        );
    }

    /**
     * Detect if the value is obviously not a valid keyword.
     *
     * @return string|null Rejection reason or null if valid
     */
    private static function detectInvalidContent(string $value): ?string
    {
        // JSON fragments
        if (self::looksLikeJson($value)) {
            return 'json_fragment';
        }

        // Multiple sentences (contains sentence-ending punctuation followed by capital)
        if (preg_match('/[.!?]\s+[A-Z]/', $value)) {
            return 'multiple_sentences';
        }

        // Contains newlines (paragraphs)
        if (str_contains($value, "\n")) {
            return 'contains_newlines';
        }

        // Too many words (likely a sentence or paragraph)
        $wordCount = str_word_count($value);
        if ($wordCount > self::MAX_WORD_COUNT) {
            return 'too_many_words';
        }

        // Contains prompt-like patterns
        if (self::looksLikePromptText($value)) {
            return 'prompt_text';
        }

        // Very long with special characters (likely metadata or serialized data)
        if (mb_strlen($value) > self::IDEAL_MAX_LENGTH && preg_match('/[{}[\]<>|\\\\]/', $value)) {
            return 'metadata_fragment';
        }

        return null;
    }

    /**
     * Check if the value looks like JSON.
     */
    private static function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);

        // Starts and ends with JSON delimiters
        if (
            (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        ) {
            return true;
        }

        // Contains JSON-like key patterns
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
        ];

        foreach ($promptPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to derive a usable keyword from problematic text.
     */
    private static function deriveKeywordFromText(string $text, int $maxLength): string
    {
        // Remove common artifacts
        $clean = preg_replace('/^(primary_keyword|keyword|topic|title)[:\s]+/i', '', $text) ?? $text;
        $clean = preg_replace('/[{}\[\]"\']+/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        // Take first meaningful words
        $words = preg_split('/\s+/', $clean) ?? [];
        $words = array_filter($words, fn (string $w): bool => mb_strlen($w) > 2);
        $words = array_slice($words, 0, 5);

        if ($words === []) {
            return '';
        }

        $result = implode(' ', $words);
        $result = self::truncate($result, $maxLength);

        // Verify the derived value is reasonable
        $wordCount = str_word_count($result);
        if ($wordCount < 1 || $wordCount > self::MAX_WORD_COUNT) {
            return '';
        }

        return $result;
    }

    /**
     * Convert input to a clean string.
     * Note: Preserves newlines for detection, then normalizes in a second pass.
     */
    private static function toCleanString(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            // Don't try to use arrays or objects as keywords
            return '';
        }

        $str = (string) $value;

        // Decode HTML entities
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip HTML tags
        $str = strip_tags($str);

        // Normalize horizontal whitespace but preserve newlines for detection
        $str = preg_replace('/[ \t]+/', ' ', $str) ?? $str;

        return trim($str);
    }

    /**
     * Final cleanup after validation - normalize all whitespace.
     */
    private static function finalNormalize(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Truncate to max length, preferring word boundaries.
     */
    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $candidate = mb_substr($value, 0, $maxLength);

        // Try to break at a word boundary
        $lastSpace = mb_strrpos($candidate, ' ');
        $minLength = (int) floor($maxLength * 0.6);

        if ($lastSpace !== false && $lastSpace >= $minLength) {
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
     *     keyword: string,
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
        string $keyword,
        string $original,
        bool $wasSanitized,
        bool $wasTruncated,
        bool $wasRejected,
        ?string $rejectionReason,
        int $maxLength,
    ): array {
        return [
            'keyword' => $keyword,
            'original_value' => $original,
            'was_sanitized' => $wasSanitized,
            'was_truncated' => $wasTruncated,
            'was_rejected' => $wasRejected,
            'rejection_reason' => $rejectionReason,
            'original_length' => mb_strlen($original),
            'persisted_length' => mb_strlen($keyword),
            'max_length' => $maxLength,
        ];
    }
}
