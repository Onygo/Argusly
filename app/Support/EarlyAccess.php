<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centralized early access mode logic.
 *
 * Provides a single source of truth for early access state and visibility rules
 * across the public marketing site.
 */
final class EarlyAccess
{
    /**
     * Page classification for public marketing pages.
     */
    public const VISIBILITY_ALWAYS = 'always';

    public const VISIBILITY_FULL_MARKETING_ONLY = 'full_marketing_only';

    public const VISIBILITY_EARLY_ACCESS_ONLY = 'early_access_only';

    public const VISIBILITY_LEGAL = 'legal';

    /**
     * Marketing pages that should be hidden in early access mode.
     * These will redirect to the early access page when accessed directly.
     */
    private const BLOCKED_MARKETING_PAGES = [
        'product.capabilities',
        'product.governance',
        'product.intelligence',
        'company.roadmap',
        'blog',
        'pricing',
    ];

    /**
     * Legal pages that must remain accessible in all modes.
     */
    private const LEGAL_PAGES = [
        'legal.index',
        'legal.privacy',
        'legal.terms',
        'legal.security',
        'legal.cookies',
        'legal.subprocessors',
    ];

    /**
     * Pages that are always visible regardless of mode.
     */
    private const ALWAYS_VISIBLE_PAGES = [
        'landing',
        'product.overview',
        'company.about',
        'company.contact',
        'early-access',
        'login',
    ];

    /**
     * Check if early access mode is enabled.
     */
    public static function enabled(): bool
    {
        return (bool) config('publishlayer.launch.soft_launch_mode', false);
    }

    /**
     * Check if full marketing navigation should be shown.
     * Returns true when NOT in early access mode.
     */
    public static function showFullMarketingNav(): bool
    {
        return ! self::enabled();
    }

    /**
     * Check if early access CTA should be shown.
     * Returns true when in early access mode.
     */
    public static function showEarlyAccessCTA(): bool
    {
        return self::enabled();
    }

    /**
     * Check if public pricing is enabled.
     */
    public static function pricingEnabled(): bool
    {
        return (bool) config('publishlayer.launch.public_pricing_enabled', true);
    }

    /**
     * Check if a specific marketing page should be accessible.
     *
     * @param  string  $pageKey  The page identifier (e.g., 'product.capabilities', 'blog')
     */
    public static function allowPublicMarketingPage(string $pageKey): bool
    {
        // Legal pages are always accessible
        if (in_array($pageKey, self::LEGAL_PAGES, true)) {
            return true;
        }

        // Always visible pages are accessible in all modes
        if (in_array($pageKey, self::ALWAYS_VISIBLE_PAGES, true)) {
            return true;
        }

        // In full marketing mode, all pages are accessible
        if (self::showFullMarketingNav()) {
            return true;
        }

        // In early access mode, blocked pages are not accessible
        return ! in_array($pageKey, self::BLOCKED_MARKETING_PAGES, true);
    }

    /**
     * Get the visibility classification for a page.
     *
     * @param  string  $pageKey  The page identifier
     */
    public static function getPageVisibility(string $pageKey): string
    {
        if (in_array($pageKey, self::LEGAL_PAGES, true)) {
            return self::VISIBILITY_LEGAL;
        }

        if (in_array($pageKey, self::ALWAYS_VISIBLE_PAGES, true)) {
            return self::VISIBILITY_ALWAYS;
        }

        if (in_array($pageKey, self::BLOCKED_MARKETING_PAGES, true)) {
            return self::VISIBILITY_FULL_MARKETING_ONLY;
        }

        return self::VISIBILITY_ALWAYS;
    }

    /**
     * Check if a page is a legal page.
     */
    public static function isLegalPage(string $pageKey): bool
    {
        return in_array($pageKey, self::LEGAL_PAGES, true);
    }

    /**
     * Get the redirect URL for blocked pages in early access mode.
     */
    public static function getBlockedPageRedirectUrl(): string
    {
        return route('public.early-access.show');
    }

    /**
     * Get the list of blocked marketing page keys.
     *
     * @return array<int, string>
     */
    public static function getBlockedMarketingPages(): array
    {
        return self::BLOCKED_MARKETING_PAGES;
    }

    /**
     * Get the list of legal page keys.
     *
     * @return array<int, string>
     */
    public static function getLegalPages(): array
    {
        return self::LEGAL_PAGES;
    }
}
