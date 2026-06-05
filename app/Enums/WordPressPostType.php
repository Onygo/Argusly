<?php

namespace App\Enums;

/**
 * Central configuration for WordPress post types.
 *
 * Maps PublishLayer content types to WordPress post types with their URL patterns.
 * This ensures consistent URL generation for internal links and proper WP REST API targeting.
 */
enum WordPressPostType: string
{
    case POST = 'post';
    case KNOWLEDGE_BASE = 'knowledge_base';
    case PAGE = 'page';

    /**
     * Get the URL path segment for this post type.
     *
     * These should match the WordPress permalink structure for each post type.
     * For custom post types, this typically matches the rewrite slug.
     */
    public function urlSegment(): string
    {
        return match ($this) {
            self::POST => 'blog',
            self::KNOWLEDGE_BASE => 'knowledge-base',
            self::PAGE => '',
        };
    }

    /**
     * Build a planned URL for this post type.
     */
    public function buildPlannedUrl(string $baseUrl, string $slug): string
    {
        $base = rtrim(trim($baseUrl), '/');
        $segment = $this->urlSegment();

        if ($segment === '') {
            return $base !== '' ? $base . '/' . $slug : '/' . $slug;
        }

        if ($base === '') {
            return '/' . $segment . '/' . $slug;
        }

        return $base . '/' . $segment . '/' . $slug;
    }

    /**
     * Get the WordPress REST API endpoint for this post type.
     */
    public function wpRestEndpoint(): string
    {
        return match ($this) {
            self::POST => 'posts',
            self::KNOWLEDGE_BASE => 'knowledge_base',
            self::PAGE => 'pages',
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::POST => 'Blog post',
            self::KNOWLEDGE_BASE => 'Knowledge base article',
            self::PAGE => 'Page',
        };
    }

    /**
     * Map a Content.type value to the corresponding WordPress post type.
     */
    public static function fromContentType(?string $contentType): self
    {
        $normalized = strtolower(trim((string) $contentType));

        return match ($normalized) {
            'knowledge_base', 'kb_article' => self::KNOWLEDGE_BASE,
            'seo_page', 'landing', 'landing_page', 'page' => self::PAGE,
            default => self::POST,
        };
    }

    /**
     * Map a brief/draft output_type to the corresponding WordPress post type.
     */
    public static function fromOutputType(?string $outputType): self
    {
        $normalized = strtolower(trim((string) $outputType));

        return match ($normalized) {
            'kb_article', 'knowledge_base' => self::KNOWLEDGE_BASE,
            'seo_page', 'landing_page' => self::PAGE,
            default => self::POST,
        };
    }

    /**
     * Available types for series content type selection.
     *
     * @return array<string, string>
     */
    public static function seriesOptions(): array
    {
        return [
            self::POST->value => self::POST->label(),
            self::KNOWLEDGE_BASE->value => self::KNOWLEDGE_BASE->label(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $item): string => $item->value, self::cases());
    }
}
