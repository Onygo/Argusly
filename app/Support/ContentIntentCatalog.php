<?php

namespace App\Support;

final class ContentIntentCatalog
{
    public const MAX_INTENT_LENGTH = 60;

    public const MAX_INTENTS_COUNT = 8;

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'educate' => 'Educate',
            'explain' => 'Explain',
            'guide' => 'Guide',
            'inform' => 'Inform',
            'commercial' => 'Commercial',
            'engage' => 'Engage',
            'convert' => 'Convert',
            'persuade' => 'Persuade',
            'compare' => 'Compare',
            'process' => 'Process',
            'strategic' => 'Strategic',
            'solution' => 'Solution',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultKeys(): array
    {
        return array_keys(self::options());
    }

    /**
     * @deprecated Use defaultKeys() instead
     * @return array<int, string>
     */
    public static function allowedKeys(): array
    {
        return self::defaultKeys();
    }

    /**
     * Normalize intent keys, supporting both default and custom intents.
     *
     * @param  array<int, string>  $keys
     * @param  bool  $allowCustom  Whether to allow custom intents not in the default list
     * @return array<int, string>
     */
    public static function normalizeKeys(array $keys, bool $allowCustom = true): array
    {
        $defaultKeys = self::defaultKeys();

        return collect($keys)
            ->map(fn ($value): string => self::normalizeIntentKey((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->filter(fn (string $value): bool => mb_strlen($value) <= self::MAX_INTENT_LENGTH)
            ->filter(function (string $value) use ($defaultKeys, $allowCustom): bool {
                // Always allow default keys
                if (in_array($value, $defaultKeys, true)) {
                    return true;
                }
                // Allow custom keys if enabled
                return $allowCustom;
            })
            ->unique(fn (string $value): string => strtolower($value))
            ->take(self::MAX_INTENTS_COUNT)
            ->values()
            ->all();
    }

    /**
     * Normalize a single intent key.
     */
    public static function normalizeIntentKey(string $value): string
    {
        $normalized = trim($value);
        $normalized = preg_replace('/\s+/', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $normalized) ?? $normalized;
        $normalized = strtolower($normalized);

        return $normalized;
    }

    /**
     * Check if an intent key is a default/predefined one.
     */
    public static function isDefaultKey(string $key): bool
    {
        return in_array(self::normalizeIntentKey($key), self::defaultKeys(), true);
    }

    /**
     * Get display label for an intent key.
     * Returns the predefined label for default keys, or formats custom keys nicely.
     */
    public static function getLabel(string $key): string
    {
        $normalized = self::normalizeIntentKey($key);
        $options = self::options();

        if (isset($options[$normalized])) {
            return $options[$normalized];
        }

        // Format custom key as readable label
        return ucfirst(str_replace(['_', '-'], ' ', $normalized));
    }

    /**
     * Build options array including custom intents for display.
     *
     * @param  array<int, string>  $customIntents
     * @return array<string, string>
     */
    public static function optionsWithCustom(array $customIntents = []): array
    {
        $options = self::options();

        foreach ($customIntents as $intent) {
            $normalized = self::normalizeIntentKey($intent);
            if ($normalized !== '' && ! isset($options[$normalized])) {
                $options[$normalized] = self::getLabel($normalized);
            }
        }

        return $options;
    }

    /**
     * Validate intents and return validation result.
     *
     * @param  array<int, string>  $intents
     * @return array{valid: bool, errors: array<int, string>, normalized: array<int, string>}
     */
    public static function validate(array $intents): array
    {
        $errors = [];
        $normalized = [];

        if (count($intents) > self::MAX_INTENTS_COUNT) {
            $errors[] = sprintf('Maximum %d intents allowed.', self::MAX_INTENTS_COUNT);
        }

        foreach ($intents as $index => $intent) {
            $trimmed = trim((string) $intent);
            if ($trimmed === '') {
                continue;
            }

            if (mb_strlen($trimmed) > self::MAX_INTENT_LENGTH) {
                $errors[] = sprintf('Intent "%s" exceeds maximum length of %d characters.', mb_substr($trimmed, 0, 20) . '...', self::MAX_INTENT_LENGTH);
                continue;
            }

            $key = self::normalizeIntentKey($trimmed);
            if ($key === '') {
                $errors[] = sprintf('Intent "%s" contains only invalid characters.', $trimmed);
                continue;
            }

            // Check for case-insensitive duplicates
            $lowerKey = strtolower($key);
            $existingLower = array_map('strtolower', $normalized);
            if (in_array($lowerKey, $existingLower, true)) {
                continue; // Skip duplicates silently
            }

            $normalized[] = $key;
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'normalized' => array_slice($normalized, 0, self::MAX_INTENTS_COUNT),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultsForOutputType(string $outputType): array
    {
        return match (self::outputFamily($outputType)) {
            'landing_page' => ['convert', 'persuade', 'explain'],
            'blog' => ['educate', 'inform', 'engage'],
            default => ['educate', 'explain', 'guide'],
        };
    }

    public static function outputFamily(string $outputType): string
    {
        return match (strtolower(trim($outputType))) {
            'seo_page', 'landing', 'landing_page' => 'landing_page',
            'article', 'blog', 'blog_post' => 'blog',
            default => 'knowledge_base',
        };
    }
}
