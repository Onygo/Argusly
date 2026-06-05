<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for publication events.
 *
 * Used for:
 * - publication.started
 * - publication.succeeded
 * - publication.failed
 * - publication.verified
 */
class PublicationPayload extends AbstractWebhookPayload
{
    public function __construct(
        private string $eventType,
        private ?Content $article = null,
        private ?ContentPublication $publication = null,
        private ?Draft $draft = null,
        private ?string $remoteId = null,
        private ?string $remoteUrl = null,
        private ?string $remoteType = null,
        private ?string $error = null,
        private ?string $provider = null,
    ) {}

    public static function started(Content $article, Draft $draft, string $provider = 'wordpress'): self
    {
        return new self(
            eventType: WebhookEventRegistry::PUBLICATION_STARTED,
            article: $article,
            draft: $draft,
            provider: $provider,
        );
    }

    public static function succeeded(
        Content $article,
        ContentPublication $publication,
        ?Draft $draft = null,
    ): self {
        return new self(
            eventType: WebhookEventRegistry::PUBLICATION_SUCCEEDED,
            article: $article,
            publication: $publication,
            draft: $draft,
            remoteId: $publication->remote_id,
            remoteUrl: $publication->remote_url,
            remoteType: $publication->remote_type,
            provider: $publication->provider,
        );
    }

    public static function failed(
        Content $article,
        string $error,
        ?Draft $draft = null,
        string $provider = 'wordpress',
    ): self {
        return new self(
            eventType: WebhookEventRegistry::PUBLICATION_FAILED,
            article: $article,
            draft: $draft,
            error: $error,
            provider: $provider,
        );
    }

    public static function verified(Content $article, ContentPublication $publication): self
    {
        return new self(
            eventType: WebhookEventRegistry::PUBLICATION_VERIFIED,
            article: $article,
            publication: $publication,
            remoteId: $publication->remote_id,
            remoteUrl: $publication->remote_url,
            remoteType: $publication->remote_type,
            provider: $publication->provider,
        );
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        $payload = [
            'article_id' => $this->article?->id,
            'title' => $this->article?->title,
            'provider' => $this->provider ?? $this->publication?->provider ?? 'wordpress',
            'workspace_id' => $this->article?->workspace_id,
            'client_site_id' => $this->article?->client_site_id,
            'destination_id' => $this->article?->content_destination_id ?? $this->publication?->destination_id,
        ];

        if ($this->publication !== null) {
            $payload['publication_id'] = $this->publication->id;
            $payload['delivery_status'] = $this->publication->delivery_status;
            $payload['remote_status'] = $this->publication->remote_status;
        }

        if ($this->draft !== null) {
            $payload['draft_id'] = $this->draft->id;
        }

        if ($this->remoteId !== null) {
            $payload['remote_id'] = $this->remoteId;
        }

        if ($this->remoteUrl !== null) {
            $payload['remote_url'] = $this->remoteUrl;
        }

        if ($this->remoteType !== null) {
            $payload['remote_type'] = $this->remoteType;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        // Add timestamp based on event type
        $payload['timestamp'] = $this->formatDateTime(now());

        return $payload;
    }

    public function links(): array
    {
        $links = [];

        if ($this->article !== null) {
            $links['article'] = $this->buildApiLink("articles/{$this->article->id}");
        }

        if ($this->remoteUrl !== null) {
            $links['remote'] = $this->remoteUrl;
        }

        return $links;
    }

    public function meta(): array
    {
        return [
            'content_destination_id' => $this->article?->content_destination_id ?? $this->publication?->destination_id,
        ];
    }
}
