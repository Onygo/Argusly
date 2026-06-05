<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ContentPublication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical public article read resource.
 *
 * Legacy /briefs and /drafts remain available for compatibility, but new
 * read-side integrations should prefer the /v1/articles contract.
 */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $publication = $this->resolveCanonicalPublication();

        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'client_site_id' => $this->client_site_id ? (string) $this->client_site_id : null,
            'content_destination_id' => $this->content_destination_id ? (string) $this->content_destination_id : null,
            'status' => (string) $this->status,
            'publish_status' => (string) ($this->publish_status ?? ''),
            'type' => (string) ($this->type ?? 'article'),
            'title' => (string) $this->title,
            'language' => (string) ($this->language?->value ?? $this->language ?? ''),
            'primary_keyword' => $this->primary_keyword,
            'seo' => [
                'title' => $this->seo_title,
                'meta_description' => $this->seo_meta_description,
                'canonical_url' => $this->seo_canonical,
                'og_image' => $this->seo_og_image,
            ],
            'publication' => $publication ? [
                'id' => (string) $publication->id,
                'provider' => (string) $publication->provider,
                'delivery_status' => (string) $publication->delivery_status,
                'remote_status' => $publication->remote_status,
                'remote' => [
                    'id' => $publication->remote_id,
                    'url' => $publication->remote_url,
                    'type' => $publication->remote_type,
                ],
            ] : null,
            'chain' => $this->series_id ? [
                'series_id' => (string) $this->series_id,
                'series_name' => (string) ($this->series?->name ?? ''),
                'article_number' => $this->seriesArticle?->article_number ? (int) $this->seriesArticle->article_number : null,
                'is_pillar' => (bool) ($this->seriesArticle?->is_pillar ?? false),
                'role' => $this->seriesArticle
                    ? ($this->seriesArticle->is_pillar ? 'pillar' : 'supporting')
                    : null,
            ] : null,
            'relationships' => [
                'brief_id' => $this->brief?->id ? (string) $this->brief->id : null,
                'drafts_count' => $this->whenCounted('drafts'),
                'publications_count' => $this->whenCounted('publications'),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'scheduled_publish_at' => $this->scheduled_publish_at?->toIso8601String(),
            ],
            'links' => [
                'self' => url('/api/v1/articles/'.$this->id),
                'drafts' => url('/api/v1/articles/'.$this->id.'/drafts'),
                'publications' => url('/api/v1/articles/'.$this->id.'/publications'),
                'brief' => $this->brief?->id ? url('/api/v1/briefs/'.$this->brief->id) : null,
            ],
        ];
    }

    private function resolveCanonicalPublication(): ?ContentPublication
    {
        if ($this->relationLoaded('publications')) {
            return $this->publications
                ->sortBy([
                    fn (ContentPublication $publication) => $publication->delivery_status === ContentPublication::STATUS_DELIVERED ? 0 : 1,
                    fn (ContentPublication $publication) => $publication->last_delivered_at ? -$publication->last_delivered_at->getTimestamp() : PHP_INT_MAX,
                ])
                ->first();
        }

        return $this->publications()
            ->orderByRaw("CASE delivery_status WHEN 'delivered' THEN 0 ELSE 1 END")
            ->latest('last_delivered_at')
            ->latest('created_at')
            ->first();
    }
}
