<?php

namespace App\Support\Webhooks;

/**
 * Base interface for webhook payload builders.
 *
 * Payload builders are responsible for constructing the `data` portion
 * of webhook envelopes. They ensure consistent payload structure across
 * all webhook events.
 *
 * ## Implementation Guidelines
 *
 * 1. Each event type should have a dedicated payload builder
 * 2. Payloads should be flat structures (avoid deep nesting)
 * 3. Include only necessary fields (minimize payload size)
 * 4. Use consistent field naming (snake_case)
 * 5. Include resource IDs for linking
 * 6. Add timestamps where relevant
 *
 * ## Example
 *
 * ```php
 * class ArticleCreatedPayload implements WebhookPayload
 * {
 *     public function __construct(
 *         private Content $article,
 *     ) {}
 *
 *     public function eventType(): string
 *     {
 *         return WebhookEventRegistry::ARTICLE_CREATED;
 *     }
 *
 *     public function toArray(): array
 *     {
 *         return [
 *             'article_id' => $this->article->id,
 *             'title' => $this->article->title,
 *             ...
 *         ];
 *     }
 * }
 * ```
 */
interface WebhookPayload
{
    /**
     * Get the event type this payload is for.
     */
    public function eventType(): string;

    /**
     * Get the event version.
     * Defaults to current version if not overridden.
     */
    public function version(): string;

    /**
     * Convert the payload to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Get optional HATEOAS-style links.
     *
     * @return array<string, string>
     */
    public function links(): array;

    /**
     * Get optional metadata (not included in payload, used for internal routing).
     *
     * @return array<string, mixed>
     */
    public function meta(): array;
}
