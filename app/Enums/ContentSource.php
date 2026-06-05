<?php

namespace App\Enums;

/**
 * Valid source values for content records.
 *
 * The source field indicates how content was originally created:
 * - WP: Imported from WordPress
 * - MANUAL: Created manually by a user in the app
 * - API: Created programmatically via API
 * - AUTOMATION: Created via content automation system
 * - IMPORT: Imported from non-WordPress bulk/import flows
 * - SYSTEM: Created or repaired by internal system tooling
 */
enum ContentSource: string
{
    case WP = 'wp';
    case MANUAL = 'manual';
    case API = 'api';
    case AUTOMATION = 'automation';
    case IMPORT = 'import';
    case SYSTEM = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::WP => 'WordPress',
            self::MANUAL => 'Manual',
            self::API => 'API',
            self::AUTOMATION => 'Automation',
            self::IMPORT => 'Import',
            self::SYSTEM => 'System',
        };
    }

    /**
     * Check if a value is a valid ContentSource.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create from string, returning null if invalid.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * Normalize known invalid values to their correct mapping.
     *
     * This handles legacy/incorrect values like 'content_automation'
     * that may have been used before this enum was introduced.
     */
    public static function normalize(string $value): self
    {
        $value = strtolower(trim($value));

        $mappings = [
            'content_automation' => self::AUTOMATION,
            'automation' => self::AUTOMATION,
            'automation_run' => self::AUTOMATION,
            'scheduled' => self::AUTOMATION,
            'series' => self::AUTOMATION,
            'generated' => self::AUTOMATION,
            'translated' => self::AUTOMATION,
            'translation' => self::AUTOMATION,
            'chained_content' => self::AUTOMATION,
            'content_chain' => self::AUTOMATION,
            'chain' => self::AUTOMATION,
            'chained_via_automation' => self::AUTOMATION,
            'wordpress' => self::WP,
            'wp_plugin' => self::WP,
            'client_ui' => self::MANUAL,
            'pl' => self::MANUAL,
            'manual' => self::MANUAL,
            'import' => self::IMPORT,
            'csv' => self::IMPORT,
            'api' => self::API,
            'headless_api' => self::API,
            'system' => self::SYSTEM,
            'console' => self::SYSTEM,
            'repair' => self::SYSTEM,
        ];

        if (isset($mappings[$value])) {
            return $mappings[$value];
        }

        // Try direct match
        $direct = self::tryFrom($value);
        if ($direct !== null) {
            return $direct;
        }

        // Default to API for programmatic sources
        return self::API;
    }

    /**
     * Check if this source indicates programmatic content creation.
     */
    public function isProgrammatic(): bool
    {
        return in_array($this, [self::API, self::AUTOMATION, self::SYSTEM], true);
    }
}
