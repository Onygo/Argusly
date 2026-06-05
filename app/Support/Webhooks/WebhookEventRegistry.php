<?php

namespace App\Support\Webhooks;

/**
 * Central registry of all webhook events.
 *
 * This class defines the canonical list of webhook events available in Argusly.
 * It provides event metadata, versioning, and categorization for the webhook system.
 *
 * ## Event Naming Convention
 *
 * Events follow the pattern: `{domain}.{action}` or `{domain}.{subdomain}.{action}`
 *
 * Examples:
 * - `article.created` - Article was created
 * - `draft.generation.succeeded` - Draft generation completed successfully
 * - `publication.succeeded` - Content was published to remote destination
 *
 * ## Event Versioning
 *
 * Events are versioned using date-based versions (e.g., "2026-03-21").
 * The version indicates the payload structure version, not the event itself.
 * Consumers should handle unknown fields gracefully.
 */
class WebhookEventRegistry
{
    /**
     * Current event version (date-based).
     * Increment this when making breaking changes to payload structure.
     */
    public const CURRENT_VERSION = '2026-03-21';

    // =========================================================================
    // Article Events - Content lifecycle
    // =========================================================================

    public const ARTICLE_CREATED = 'article.created';
    public const ARTICLE_UPDATED = 'article.updated';
    public const ARTICLE_SUBMITTED = 'article.submitted';
    public const ARTICLE_APPROVED = 'article.approved';
    public const ARTICLE_REJECTED = 'article.rejected';
    public const ARTICLE_SCHEDULED = 'article.scheduled';
    public const ARTICLE_ARCHIVED = 'article.archived';

    // =========================================================================
    // Draft Events - Draft generation lifecycle
    // =========================================================================

    public const DRAFT_GENERATION_STARTED = 'draft.generation.started';
    public const DRAFT_GENERATION_SUCCEEDED = 'draft.generation.succeeded';
    public const DRAFT_GENERATION_FAILED = 'draft.generation.failed';
    public const DRAFT_TRANSLATION_SUCCEEDED = 'draft.translation.succeeded';

    // =========================================================================
    // Publication Events - Remote publishing lifecycle
    // =========================================================================

    public const PUBLICATION_STARTED = 'publication.started';
    public const PUBLICATION_SUCCEEDED = 'publication.succeeded';
    public const PUBLICATION_FAILED = 'publication.failed';
    public const PUBLICATION_VERIFIED = 'publication.verified';

    // =========================================================================
    // Media Events - Image/asset generation
    // =========================================================================

    public const MEDIA_GENERATED = 'media.generated';

    // =========================================================================
    // SEO Events - SEO audit lifecycle
    // =========================================================================

    public const SEO_AUDIT_COMPLETED = 'seo_audit.completed';
    public const SEO_AUDIT_FAILED = 'seo_audit.failed';

    // =========================================================================
    // System Events - Platform notifications
    // =========================================================================

    public const CREDITS_LOW = 'credits.low';

    // =========================================================================
    // Legacy Events (Deprecated - will be removed in future version)
    // =========================================================================

    /**
     * @deprecated Use DRAFT_GENERATION_SUCCEEDED instead
     */
    public const LEGACY_DRAFT_GENERATION_COMPLETED = 'draft.generation.completed';

    /**
     * @deprecated Use DRAFT_TRANSLATION_SUCCEEDED instead
     */
    public const LEGACY_DRAFT_TRANSLATED = 'draft.translated';

    /**
     * @deprecated Use ARTICLE_CREATED instead
     */
    public const LEGACY_BRIEF_CREATED = 'brief.created';

    /**
     * Get all available event types grouped by category.
     *
     * @return array<string, array<string, array{event: string, description: string, deprecated?: bool}>>
     */
    public static function catalog(): array
    {
        return [
            'article' => [
                self::ARTICLE_CREATED => [
                    'event' => self::ARTICLE_CREATED,
                    'description' => 'Fired when a new article (content) is created.',
                ],
                self::ARTICLE_UPDATED => [
                    'event' => self::ARTICLE_UPDATED,
                    'description' => 'Fired when an article is updated (title, SEO fields, etc.).',
                ],
                self::ARTICLE_SUBMITTED => [
                    'event' => self::ARTICLE_SUBMITTED,
                    'description' => 'Fired when an article is submitted for review.',
                ],
                self::ARTICLE_APPROVED => [
                    'event' => self::ARTICLE_APPROVED,
                    'description' => 'Fired when an article is approved for publication.',
                ],
                self::ARTICLE_REJECTED => [
                    'event' => self::ARTICLE_REJECTED,
                    'description' => 'Fired when an article is rejected.',
                ],
                self::ARTICLE_SCHEDULED => [
                    'event' => self::ARTICLE_SCHEDULED,
                    'description' => 'Fired when an article is scheduled for future publication.',
                ],
                self::ARTICLE_ARCHIVED => [
                    'event' => self::ARTICLE_ARCHIVED,
                    'description' => 'Fired when an article is archived.',
                ],
            ],
            'draft' => [
                self::DRAFT_GENERATION_STARTED => [
                    'event' => self::DRAFT_GENERATION_STARTED,
                    'description' => 'Fired when draft generation begins.',
                ],
                self::DRAFT_GENERATION_SUCCEEDED => [
                    'event' => self::DRAFT_GENERATION_SUCCEEDED,
                    'description' => 'Fired when draft generation completes successfully.',
                ],
                self::DRAFT_GENERATION_FAILED => [
                    'event' => self::DRAFT_GENERATION_FAILED,
                    'description' => 'Fired when draft generation fails.',
                ],
                self::DRAFT_TRANSLATION_SUCCEEDED => [
                    'event' => self::DRAFT_TRANSLATION_SUCCEEDED,
                    'description' => 'Fired when draft translation completes successfully.',
                ],
            ],
            'publication' => [
                self::PUBLICATION_STARTED => [
                    'event' => self::PUBLICATION_STARTED,
                    'description' => 'Fired when content publication to a remote destination begins.',
                ],
                self::PUBLICATION_SUCCEEDED => [
                    'event' => self::PUBLICATION_SUCCEEDED,
                    'description' => 'Fired when content is successfully published to a remote destination.',
                ],
                self::PUBLICATION_FAILED => [
                    'event' => self::PUBLICATION_FAILED,
                    'description' => 'Fired when content publication fails.',
                ],
                self::PUBLICATION_VERIFIED => [
                    'event' => self::PUBLICATION_VERIFIED,
                    'description' => 'Fired when a publication is verified to exist on the remote destination.',
                ],
            ],
            'media' => [
                self::MEDIA_GENERATED => [
                    'event' => self::MEDIA_GENERATED,
                    'description' => 'Fired when a media asset (image) is generated.',
                ],
            ],
            'seo' => [
                self::SEO_AUDIT_COMPLETED => [
                    'event' => self::SEO_AUDIT_COMPLETED,
                    'description' => 'Fired when an SEO audit completes successfully.',
                ],
                self::SEO_AUDIT_FAILED => [
                    'event' => self::SEO_AUDIT_FAILED,
                    'description' => 'Fired when an SEO audit fails.',
                ],
            ],
            'system' => [
                self::CREDITS_LOW => [
                    'event' => self::CREDITS_LOW,
                    'description' => 'Fired when workspace credits fall below threshold.',
                ],
            ],
            'legacy' => [
                self::LEGACY_DRAFT_GENERATION_COMPLETED => [
                    'event' => self::LEGACY_DRAFT_GENERATION_COMPLETED,
                    'description' => 'DEPRECATED: Use draft.generation.succeeded instead.',
                    'deprecated' => true,
                ],
                self::LEGACY_DRAFT_TRANSLATED => [
                    'event' => self::LEGACY_DRAFT_TRANSLATED,
                    'description' => 'DEPRECATED: Use draft.translation.succeeded instead.',
                    'deprecated' => true,
                ],
                self::LEGACY_BRIEF_CREATED => [
                    'event' => self::LEGACY_BRIEF_CREATED,
                    'description' => 'DEPRECATED: Use article.created instead.',
                    'deprecated' => true,
                ],
            ],
        ];
    }

    /**
     * Get all event type strings (flat list).
     *
     * @return array<string>
     */
    public static function allEvents(): array
    {
        return [
            // Article events
            self::ARTICLE_CREATED,
            self::ARTICLE_UPDATED,
            self::ARTICLE_SUBMITTED,
            self::ARTICLE_APPROVED,
            self::ARTICLE_REJECTED,
            self::ARTICLE_SCHEDULED,
            self::ARTICLE_ARCHIVED,
            // Draft events
            self::DRAFT_GENERATION_STARTED,
            self::DRAFT_GENERATION_SUCCEEDED,
            self::DRAFT_GENERATION_FAILED,
            self::DRAFT_TRANSLATION_SUCCEEDED,
            // Publication events
            self::PUBLICATION_STARTED,
            self::PUBLICATION_SUCCEEDED,
            self::PUBLICATION_FAILED,
            self::PUBLICATION_VERIFIED,
            // Media events
            self::MEDIA_GENERATED,
            // SEO events
            self::SEO_AUDIT_COMPLETED,
            self::SEO_AUDIT_FAILED,
            // System events
            self::CREDITS_LOW,
            // Legacy events (still supported for backwards compatibility)
            self::LEGACY_DRAFT_GENERATION_COMPLETED,
            self::LEGACY_DRAFT_TRANSLATED,
            self::LEGACY_BRIEF_CREATED,
        ];
    }

    /**
     * Get non-deprecated events only.
     *
     * @return array<string>
     */
    public static function activeEvents(): array
    {
        return array_filter(
            self::allEvents(),
            fn (string $event) => ! str_starts_with($event, 'brief.') && $event !== self::LEGACY_DRAFT_GENERATION_COMPLETED && $event !== self::LEGACY_DRAFT_TRANSLATED
        );
    }

    /**
     * Check if an event is deprecated.
     */
    public static function isDeprecated(string $event): bool
    {
        return in_array($event, [
            self::LEGACY_DRAFT_GENERATION_COMPLETED,
            self::LEGACY_DRAFT_TRANSLATED,
            self::LEGACY_BRIEF_CREATED,
        ], true);
    }

    /**
     * Get the replacement event for a deprecated event.
     */
    public static function getReplacementEvent(string $deprecatedEvent): ?string
    {
        return match ($deprecatedEvent) {
            self::LEGACY_DRAFT_GENERATION_COMPLETED => self::DRAFT_GENERATION_SUCCEEDED,
            self::LEGACY_DRAFT_TRANSLATED => self::DRAFT_TRANSLATION_SUCCEEDED,
            self::LEGACY_BRIEF_CREATED => self::ARTICLE_CREATED,
            default => null,
        };
    }

    /**
     * Validate that an event type is recognized.
     */
    public static function isValid(string $event): bool
    {
        return in_array($event, self::allEvents(), true) || $event === '*';
    }
}
