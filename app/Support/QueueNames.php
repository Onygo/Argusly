<?php

namespace App\Support;

/**
 * Centralized queue name reference.
 *
 * Use these constants to avoid accidental typos or confusion between
 * queue connection names and queue names.
 *
 * IMPORTANT: The queue connection (e.g., 'database') is NOT the same as
 * the queue name (e.g., 'default'). Do not use config('queue.default')
 * as a queue name - that returns the connection name, not the queue name.
 *
 * Production worker should listen to all these queues:
 * ai-low,generation,agentic-marketing,intelligence,default,deliveries,billing,markdown,emails,brief-intelligence,research,content-network
 */
final class QueueNames
{
    /**
     * Default queue for general-purpose jobs.
     */
    public const DEFAULT = 'default';

    /**
     * AI content generation (drafts, images, comparisons).
     */
    public const GENERATION = 'generation';

    /**
     * AI analysis and intelligence (lower priority than generation).
     */
    public const AI_LOW = 'ai-low';

    /**
     * Autonomous and manually approved Agentic Marketing execution.
     */
    public const AGENTIC_MARKETING = 'agentic-marketing';

    /**
     * Opportunity, lifecycle, cluster, and learning intelligence jobs.
     */
    public const INTELLIGENCE = 'intelligence';

    /**
     * External delivery (WordPress, Laravel connector, webhooks).
     */
    public const DELIVERIES = 'deliveries';

    /**
     * Billing and subscription lifecycle.
     */
    public const BILLING = 'billing';

    /**
     * Markdown artifact generation.
     */
    public const MARKDOWN = 'markdown';

    /**
     * Email sending (onboarding, notifications).
     */
    public const EMAILS = 'emails';

    /**
     * Brief intelligence and enhancement.
     */
    public const BRIEF_INTELLIGENCE = 'brief-intelligence';

    /**
     * Research project processing.
     */
    public const RESEARCH = 'research';

    /**
     * Content network analysis.
     */
    public const CONTENT_NETWORK = 'content-network';

    /**
     * Get all valid queue names for worker configuration.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::AI_LOW,
            self::GENERATION,
            self::AGENTIC_MARKETING,
            self::INTELLIGENCE,
            self::DEFAULT,
            self::DELIVERIES,
            self::BILLING,
            self::MARKDOWN,
            self::EMAILS,
            self::BRIEF_INTELLIGENCE,
            self::RESEARCH,
            self::CONTENT_NETWORK,
        ];
    }

    /**
     * Get comma-separated queue names for worker command.
     */
    public static function forWorker(): string
    {
        return implode(',', self::all());
    }
}
