<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'brief_id' => (string) $this->brief_id,
            'content_id' => (string) $this->content_id,
            'client_site_id' => $this->client_site_id,
            'content_destination_id' => $this->content_destination_id,
            'status' => (string) $this->status,
            'title' => (string) $this->title,
            'language' => (string) ($this->language?->value ?? $this->language),
            'draft_type' => (string) ($this->draft_type?->value ?? $this->draft_type ?? 'original'),
            'source_draft_id' => $this->source_draft_id ? (string) $this->source_draft_id : null,
            'output_type' => (string) $this->output_type,
            'content_html' => $this->content_html,
            'seo' => [
                'slug' => data_get($this->meta, 'slug'),
                'meta_title' => $this->seo_title,
                'meta_description' => $this->seo_meta_description,
                'canonical_url' => $this->seo_canonical,
            ],
            'summary' => [
                'excerpt' => data_get($this->meta, 'excerpt'),
                'key_takeaways' => (array) data_get($this->meta, 'key_takeaways', []),
            ],
            'cta' => [
                'text' => data_get($this->meta, 'call_to_action', data_get($this->meta, 'cta.text')),
                'url' => data_get($this->meta, 'cta.url'),
            ],
            'usage' => [
                'credits_used' => (int) ($this->credit_cost ?? 0),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
