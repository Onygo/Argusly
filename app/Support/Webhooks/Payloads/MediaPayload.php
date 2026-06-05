<?php

namespace App\Support\Webhooks\Payloads;

use App\Models\Content;
use App\Models\ContentImage;
use App\Support\Webhooks\AbstractWebhookPayload;
use App\Support\Webhooks\WebhookEventRegistry;

/**
 * Payload builder for media/image events.
 *
 * Used for:
 * - media.generated
 */
class MediaPayload extends AbstractWebhookPayload
{
    public function __construct(
        private ContentImage $image,
        private Content $article,
        private string $eventType = WebhookEventRegistry::MEDIA_GENERATED,
    ) {}

    public static function generated(ContentImage $image, Content $article): self
    {
        return new self($image, $article);
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function toArray(): array
    {
        return [
            'media_id' => $this->image->id,
            'article_id' => $this->article->id,
            'type' => $this->image->type,
            'status' => $this->image->status,
            'url' => $this->image->url,
            'storage_path' => $this->image->storage_path,
            'width' => $this->image->width,
            'height' => $this->image->height,
            'format' => $this->image->format,
            'file_size' => $this->image->file_size,
            'alt_text' => $this->image->alt_text,
            'workspace_id' => $this->article->workspace_id,
            'client_site_id' => $this->article->client_site_id,
            'generated_at' => $this->formatDateTime($this->image->created_at),
        ];
    }

    public function links(): array
    {
        $links = [
            'article' => $this->buildApiLink("articles/{$this->article->id}"),
        ];

        if ($this->image->url) {
            $links['media'] = $this->image->url;
        }

        return $links;
    }

    public function meta(): array
    {
        return [
            'content_destination_id' => $this->article->content_destination_id,
        ];
    }
}
