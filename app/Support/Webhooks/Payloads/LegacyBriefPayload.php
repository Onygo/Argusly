<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Brief;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Legacy payload builder for brief.created event.
 *
 * @deprecated Use ArticlePayload::created() instead.
 *
 * This payload maintains backwards compatibility with existing webhook
 * consumers that expect the legacy brief.created event format.
 */
class LegacyBriefPayload extends AbstractWebhookPayload
{
    public function __construct(
        private Brief $brief,
    ) {}

    public static function created(Brief $brief): self
    {
        return new self($brief);
    }

    public function eventType(): string
    {
        return WebhookEventRegistry::LEGACY_BRIEF_CREATED;
    }

    public function toArray(): array
    {
        return [
            'brief_id' => $this->brief->id,
            'content_id' => $this->brief->content_id,
            'title' => $this->brief->title,
            'topic' => $this->brief->topic,
            'primary_keyword' => $this->brief->primary_keyword,
            'status' => $this->brief->status,
            'workspace_id' => $this->brief->workspace_id,
            'client_site_id' => $this->brief->client_site_id,
            'destination_id' => $this->brief->content_destination_id,
        ];
    }

    public function links(): array
    {
        return [
            'brief' => $this->buildApiLink("briefs/{$this->brief->id}"),
        ];
    }

    public function meta(): array
    {
        return [
            'content_destination_id' => $this->brief->content_destination_id,
        ];
    }
}
