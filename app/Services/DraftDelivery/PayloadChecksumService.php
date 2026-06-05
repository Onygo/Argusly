<?php

namespace App\Services\DraftDelivery;

/**
 * Calculates checksums for delivery payloads to detect changes.
 *
 * The checksum covers all meaningful content that affects the published result,
 * enabling skip-delivery optimizations when content hasn't changed.
 */
class PayloadChecksumService
{
    /**
     * Fields that constitute the "meaningful" payload for checksum calculation.
     * Changes to these fields should trigger a new delivery.
     */
    private const CHECKSUM_FIELDS = [
        // Core content
        'title',
        'content_html',
        'slug',
        'excerpt',
        'status',

        // SEO fields
        'seo_title',
        'seo_meta_description',
        'seo_h1',
        'primary_keyword',
        'robots_index',
        'robots_follow',
        'schema_type',
        'seo_canonical',
        'seo_og_title',
        'seo_og_description',
        'seo_og_image',
        'seo_twitter_title',
        'seo_twitter_description',

        // Media
        'featured_image_url',
        'og_image_url',
    ];

    /**
     * Calculate SHA-256 checksum for a delivery payload.
     *
     * @param  array<string, mixed>  $payload  The full delivery payload
     * @return string 64-character hex string
     */
    public function calculateChecksum(array $payload): string
    {
        $checksumData = $this->extractChecksumFields($payload);
        $normalized = $this->normalizeForChecksum($checksumData);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }

    /**
     * Compare a payload checksum against a stored checksum.
     *
     * @return bool True if checksums match (no delivery needed)
     */
    public function checksumMatches(array $payload, ?string $storedChecksum): bool
    {
        if ($storedChecksum === null || $storedChecksum === '') {
            return false;
        }

        $currentChecksum = $this->calculateChecksum($payload);

        return hash_equals($storedChecksum, $currentChecksum);
    }

    /**
     * Determine if delivery can be skipped based on checksum.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $storedChecksum
     * @param  bool  $forceDelivery  If true, never skip
     * @return array{skip: bool, reason: string, current_checksum: string}
     */
    public function shouldSkipDelivery(
        array $payload,
        ?string $storedChecksum,
        bool $forceDelivery = false
    ): array {
        $currentChecksum = $this->calculateChecksum($payload);

        if ($forceDelivery) {
            return [
                'skip' => false,
                'reason' => 'force_delivery_requested',
                'current_checksum' => $currentChecksum,
            ];
        }

        if ($storedChecksum === null || $storedChecksum === '') {
            return [
                'skip' => false,
                'reason' => 'no_previous_checksum',
                'current_checksum' => $currentChecksum,
            ];
        }

        if (hash_equals($storedChecksum, $currentChecksum)) {
            return [
                'skip' => true,
                'reason' => 'checksum_unchanged',
                'current_checksum' => $currentChecksum,
            ];
        }

        return [
            'skip' => false,
            'reason' => 'checksum_changed',
            'current_checksum' => $currentChecksum,
        ];
    }

    /**
     * Extract only the fields relevant for checksum calculation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractChecksumFields(array $payload): array
    {
        $extracted = [];

        foreach (self::CHECKSUM_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $extracted[$field] = $payload[$field];
            }
        }

        // Also include taxonomies from meta if present
        $meta = $payload['meta'] ?? [];
        if (is_array($meta)) {
            $taxonomyFields = ['categories', 'tags', 'taxonomy', 'terms'];
            foreach ($taxonomyFields as $taxField) {
                if (isset($meta[$taxField])) {
                    $extracted["meta.{$taxField}"] = $meta[$taxField];
                }
            }
        }

        return $extracted;
    }

    /**
     * Normalize data for consistent checksum calculation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeForChecksum(array $data): array
    {
        // Sort keys for consistent ordering
        ksort($data);

        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively normalize arrays
                $normalized[$key] = $this->normalizeForChecksum($value);
            } elseif (is_string($value)) {
                // Trim whitespace and normalize line endings
                $normalized[$key] = str_replace(["\r\n", "\r"], "\n", trim($value));
            } elseif (is_bool($value)) {
                // Consistent boolean representation
                $normalized[$key] = $value ? '1' : '0';
            } elseif ($value === null) {
                // Skip null values for consistency
                continue;
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Get the list of fields used for checksum calculation.
     * Useful for debugging and documentation.
     *
     * @return array<string>
     */
    public static function getChecksumFields(): array
    {
        return self::CHECKSUM_FIELDS;
    }
}
