<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Content;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for article (content) events.
 *
 * Used for:
 * - article.created
 * - article.updated
 * - article.submitted
 * - article.approved
 * - article.rejected
 * - article.scheduled
 * - article.archived
 */
class ArticlePayload extends AbstractWebhookPayload
{
    public function __construct(
        private Content $article,
        private string $eventType,
        private ?string $reason = null,
        private ?string $actorUserId = null,
    ) {}

    public static function created(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_CREATED, null, $actorUserId);
    }

    public static function updated(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_UPDATED, null, $actorUserId);
    }

    public static function submitted(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_SUBMITTED, null, $actorUserId);
    }

    public static function approved(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_APPROVED, null, $actorUserId);
    }

    public static function rejected(Content $article, ?string $reason = null, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_REJECTED, $reason, $actorUserId);
    }

    public static function scheduled(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_SCHEDULED, null, $actorUserId);
    }

    public static function archived(Content $article, ?string $actorUserId = null): self
    {
        return new self($article, WebhookEventRegistry::ARTICLE_ARCHIVED, null, $actorUserId);
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        $this->article->loadMissing('seriesArticle');

        $payload = [
            'article_id' => $this->article->id,
            'title' => $this->article->title,
            'status' => $this->article->status,
            'type' => $this->article->type,
            'language' => $this->article->language?->value,
            'primary_keyword' => $this->article->primary_keyword,
            'seo_title' => $this->article->seo_title,
            'workspace_id' => $this->article->workspace_id,
            'client_site_id' => $this->article->client_site_id,
            'destination_id' => $this->article->content_destination_id,
            'series_id' => $this->article->series_id,
            'series_article_number' => $this->article->seriesArticle?->article_number,
            'is_pillar' => (bool) ($this->article->seriesArticle?->is_pillar ?? false),
            'series_role' => $this->article->seriesArticle
                ? ($this->article->seriesArticle->is_pillar ? 'pillar' : 'supporting')
                : null,
            'created_at' => $this->formatDateTime($this->article->created_at),
            'updated_at' => $this->formatDateTime($this->article->updated_at),
        ];

        // Add scheduled publish time for scheduled events
        if ($this->eventType === WebhookEventRegistry::ARTICLE_SCHEDULED && $this->article->scheduled_publish_at) {
            $payload['scheduled_publish_at'] = $this->formatDateTime($this->article->scheduled_publish_at);
        }

        // Add rejection reason if applicable
        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        // Add actor information if available
        if ($this->actorUserId !== null) {
            $payload['actor_user_id'] = $this->actorUserId;
        }

        return $payload;
    }

    public function links(): array
    {
        return [
            'article' => $this->buildApiLink("articles/{$this->article->id}"),
        ];
    }

    public function meta(): array
    {
        return [
            'content_destination_id' => $this->article->content_destination_id,
        ];
    }
}
