<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LlmJsonNormalizer
{
    /**
     * Detailed decode result with failure diagnostics.
     *
     * @return array{decoded: array<string,mixed>|null, error: string|null, recovery_used: string|null}
     */
    public function decodeWithDiagnostics(string $text, ?string $provider = null): array
    {
        $prepared = $this->prepare($text);
        if ($prepared === '') {
            return ['decoded' => null, 'error' => 'empty_input', 'recovery_used' => null];
        }

        $candidates = array_values(array_unique(array_filter([
            $prepared,
            $this->extractFirstJsonPayload($prepared),
        ], fn (?string $candidate): bool => is_string($candidate) && trim($candidate) !== '')));

        $initialError = null;

        foreach ($candidates as $index => $candidate) {
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return ['decoded' => $decoded, 'error' => null, 'recovery_used' => $index === 0 ? null : 'extract_first_payload'];
            }

            $initialError ??= json_last_error_msg();

            $repaired = $this->repairJsonString($candidate);
            if ($repaired !== $candidate) {
                $decoded = json_decode($repaired, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_array($decoded)) {
                    Log::debug('LLM JSON repaired after decode failure', [
                        'provider' => $provider,
                        'original_error' => $initialError,
                        'recovery_strategy' => $index === 0 ? 'repair' : 'extract_and_repair',
                        'text_preview' => Str::limit($text, 200),
                    ]);

                    return ['decoded' => $decoded, 'error' => null, 'recovery_used' => $index === 0 ? 'repair' : 'extract_and_repair'];
                }
            }
        }

        // Try truncated JSON completion as last resort
        $truncatedResult = $this->attemptTruncatedJsonCompletion($prepared, $provider);
        if ($truncatedResult !== null) {
            Log::debug('LLM JSON recovered via truncated completion', [
                'provider' => $provider,
                'original_error' => $initialError,
                'text_preview' => Str::limit($text, 200),
            ]);

            return ['decoded' => $truncatedResult, 'error' => null, 'recovery_used' => 'truncated_completion'];
        }

        Log::debug('LLM JSON decode failed', [
            'provider' => $provider,
            'error' => $initialError ?: json_last_error_msg(),
            'text_preview' => Str::limit($text, 200),
        ]);

        return ['decoded' => null, 'error' => $initialError ?: 'unknown_json_error', 'recovery_used' => null];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function decode(string $text, ?string $provider = null): ?array
    {
        return $this->decodeWithDiagnostics($text, $provider)['decoded'];
    }

    private function prepare(string $text): string
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $clean = str_replace(["\r\n", "\r"], "\n", $clean);
        $clean = trim($clean);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $clean, $matches) === 1) {
            $clean = trim((string) ($matches[1] ?? ''));
        }

        return $clean;
    }

    private function extractFirstJsonPayload(string $text): ?string
    {
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if (! in_array($text[$start], ['{', '['], true)) {
                continue;
            }

            $payload = $this->extractBalancedJson($text, $start);
            if ($payload !== null) {
                return $payload;
            }
        }

        return null;
    }

    private function extractBalancedJson(string $text, int $start): ?string
    {
        $length = strlen($text);
        $stack = [];
        $inString = false;
        $isEscaped = false;

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($inString) {
                if ($isEscaped) {
                    $isEscaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $isEscaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $stack[] = '}';

                continue;
            }

            if ($char === '[') {
                $stack[] = ']';

                continue;
            }

            if ($stack !== [] && $char === end($stack)) {
                array_pop($stack);

                if ($stack === []) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    private function repairJsonString(string $json): string
    {
        // Ensure the input is valid UTF-8. If not, attempt to fix it.
        if (! mb_check_encoding($json, 'UTF-8')) {
            // Try to convert from potentially mixed encoding, preserving what we can
            $json = mb_convert_encoding($json, 'UTF-8', 'UTF-8');
        }

        $result = '';
        $inString = false;
        $isEscaped = false;

        // Use grapheme iteration for proper UTF-8 character handling
        // Fall back to mb_str_split for compatibility
        $chars = function_exists('grapheme_str_split')
            ? grapheme_str_split($json) ?: mb_str_split($json, 1, 'UTF-8')
            : mb_str_split($json, 1, 'UTF-8');

        foreach ($chars as $char) {
            // For single-byte ASCII characters, use ord() for control char detection
            $isSingleByteAscii = strlen($char) === 1;
            $ord = $isSingleByteAscii ? ord($char) : 128; // Multi-byte chars have ord > 127

            if ($inString) {
                if ($isEscaped) {
                    $result .= $char;
                    $isEscaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $result .= $char;
                    $isEscaped = true;

                    continue;
                }

                if ($char === '"') {
                    $result .= $char;
                    $inString = false;

                    continue;
                }

                // Only escape ASCII control characters (single-byte with ord < 32 or ord === 127)
                // Multi-byte UTF-8 characters are preserved as-is
                if ($isSingleByteAscii && ($ord < 32 || $ord === 127)) {
                    $result .= match ($char) {
                        "\n" => '\n',
                        "\r" => '\r',
                        "\t" => '\t',
                        "\f" => '\f',
                        "\b" => '\b',
                        default => sprintf('\u%04x', $ord),
                    };

                    continue;
                }

                $result .= $char;

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $result .= $char;

                continue;
            }

            // Strip control characters outside of strings (except whitespace)
            if ($isSingleByteAscii && ($ord < 32 || $ord === 127) && ! in_array($char, ["\n", "\r", "\t"], true)) {
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * Attempt to complete truncated JSON by closing open structures.
     *
     * @return array<string,mixed>|null
     */
    private function attemptTruncatedJsonCompletion(string $text, ?string $provider = null): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '' || (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '['))) {
            return null;
        }

        // Quick check: if it's already valid, return it
        $decoded = json_decode($trimmed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Check for truncation indicators
        $lastChar = substr($trimmed, -1);
        $endsCleanly = in_array($lastChar, ['}', ']'], true);

        // If it ends cleanly but still fails, it's not a simple truncation issue
        if ($endsCleanly) {
            return null;
        }

        // Attempt to complete the JSON by tracking open structures
        $completed = $this->completeJsonStructure($trimmed);
        if ($completed === null) {
            return null;
        }

        // Repair and decode the completed structure
        $repaired = $this->repairJsonString($completed);
        $decoded = json_decode($repaired, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Attempt to complete a truncated JSON string by closing open structures.
     */
    private function completeJsonStructure(string $json): ?string
    {
        $stack = [];
        $inString = false;
        $isEscaped = false;
        $length = strlen($json);
        $lastValidPos = 0;

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if ($inString) {
                if ($isEscaped) {
                    $isEscaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $isEscaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                    $lastValidPos = $index;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $stack[] = '}';
                $lastValidPos = $index;

                continue;
            }

            if ($char === '[') {
                $stack[] = ']';
                $lastValidPos = $index;

                continue;
            }

            if ($stack !== [] && $char === end($stack)) {
                array_pop($stack);
                $lastValidPos = $index;
            }
        }

        // If we're still inside a string, the truncation is in the middle of a value
        // Try to close the string first
        $completed = $json;
        if ($inString) {
            // Find the last complete value position by looking for the last quote before truncation
            $completed .= '"';
        }

        // Close all open structures in reverse order
        if ($stack !== []) {
            // If we might be in the middle of a value after a colon, add null
            $lastNonSpace = trim(substr($completed, -20));
            if (preg_match('/[:,]$/s', $lastNonSpace)) {
                $completed .= 'null';
            }

            $completed .= implode('', array_reverse($stack));
        }

        return $completed;
    }

    /**
     * Extract a specific string field value from potentially malformed JSON.
     * Used as a last-resort fallback when full JSON parsing fails.
     *
     * @return string|null The extracted value, or null if not found
     */
    public function extractFieldValue(string $text, string $fieldName): ?string
    {
        $prepared = $this->prepare($text);
        if ($prepared === '') {
            return null;
        }

        // Try to find the field using a pattern that handles escaped quotes in the value
        // Pattern: "field_name":"value" or "field_name": "value"
        $pattern = '/"' . preg_quote($fieldName, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';

        if (preg_match($pattern, $prepared, $matches)) {
            $value = $matches[1];
            // Unescape JSON string escapes
            $value = preg_replace_callback('/\\\\(.)/s', function ($m) {
                return match ($m[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    default => $m[1],
                };
            }, $value) ?? $value;

            return $value;
        }

        // Try extracting a truncated string value (ends without closing quote)
        $truncatedPattern = '/"' . preg_quote($fieldName, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)$/s';
        if (preg_match($truncatedPattern, $prepared, $matches)) {
            $value = $matches[1];
            // Unescape JSON string escapes
            $value = preg_replace_callback('/\\\\(.)/s', function ($m) {
                return match ($m[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    default => $m[1],
                };
            }, $value) ?? $value;

            return $value;
        }

        return null;
    }

    /**
     * Check if the text appears to be truncated JSON.
     */
    public function isTruncatedJson(string $text): bool
    {
        $prepared = $this->prepare($text);
        if ($prepared === '') {
            return false;
        }

        // Must start with { or [
        if (! str_starts_with($prepared, '{') && ! str_starts_with($prepared, '[')) {
            return false;
        }

        // Try to parse - if it succeeds, it's not truncated
        $decoded = json_decode($prepared, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($decoded)) {
            return false;
        }

        // Check if it ends with an incomplete structure
        $lastChar = substr(trim($prepared), -1);

        return ! in_array($lastChar, ['}', ']'], true);
    }
}
