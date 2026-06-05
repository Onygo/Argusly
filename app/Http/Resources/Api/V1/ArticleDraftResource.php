<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'article_id' => (string) $this->content_id,
            'brief_id' => $this->brief_id ? (string) $this->brief_id : null,
            'status' => (string) $this->status,
            'title' => (string) $this->title,
            'language' => (string) ($this->language?->value ?? $this->language),
            'draft_type' => (string) ($this->draft_type?->value ?? $this->draft_type ?? 'original'),
            'output_type' => (string) $this->output_type,
            'seo' => [
                'slug' => data_get($this->meta, 'slug'),
                'meta_title' => $this->seo_title,
                'meta_description' => $this->seo_meta_description,
                'canonical_url' => $this->seo_canonical,
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
            'links' => [
                'self' => url('/api/v1/drafts/'.$this->id),
                'article' => url('/api/v1/articles/'.$this->content_id),
            ],
        ];
    }
}
