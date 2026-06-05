<?php

namespace App\Enums;

/**
 * Valid type values for content records.
 *
 * The type field indicates the content format/category:
 * - ARTICLE: Standard blog posts and articles (default)
 * - KNOWLEDGE_BASE: Knowledge base/help center articles
 * - SEO_PAGE: SEO landing pages
 * - PRESS_RELEASE: Press releases
 *
 * Note: The database uses ENUM with these exact values.
 * The normalize() method maps common aliases to canonical values.
 */
enum ContentType: string
{
    case ARTICLE = 'article';
    case KNOWLEDGE_BASE = 'knowledge_base';
    case SEO_PAGE = 'seo_page';
    case PRESS_RELEASE = 'press_release';

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
            self::ARTICLE => 'Article',
            self::KNOWLEDGE_BASE => 'Knowledge Base',
            self::SEO_PAGE => 'SEO Page',
            self::PRESS_RELEASE => 'Press Release',
        };
    }

    /**
     * Check if a value is a valid ContentType.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Normalize a content type value to a valid enum value.
     *
     * Maps common aliases and legacy values to canonical enum values:
     * - 'blog', 'blog_post', 'post' => 'article'
     * - 'kb_article', 'kb' => 'knowledge_base'
     * - 'landing', 'landing_page', 'page' => 'seo_page'
     * - 'press', 'pr' => 'press_release'
     */
    public static function normalize(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            // Article aliases (most common - blog posts)
            'article', 'blog', 'blog_post', 'post', 'kb_article' => self::ARTICLE,

            // Knowledge base aliases
            'knowledge_base', 'kb', 'help', 'help_center', 'docs' => self::KNOWLEDGE_BASE,

            // SEO page aliases
            'seo_page', 'landing', 'landing_page', 'page' => self::SEO_PAGE,

            // Press release aliases
            'press_release', 'press', 'pr' => self::PRESS_RELEASE,

            // Default to article for unknown values
            default => self::ARTICLE,
        };
    }

    /**
     * Try to create from a string value, returning null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        $normalized = strtolower(trim($value));

        return self::tryFrom($normalized);
    }

    /**
     * Create from a string value with normalization.
     * Always returns a valid enum value (defaults to ARTICLE).
     */
    public static function fromString(string $value): self
    {
        return self::normalize($value);
    }
}
