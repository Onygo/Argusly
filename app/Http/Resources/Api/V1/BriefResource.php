<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BriefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) optional($this->clientSite)->workspace_id,
            'client_site_id' => $this->client_site_id,
            'content_destination_id' => $this->content_destination_id,
            'content_id' => $this->content_id,
            'status' => (string) $this->status,
            'source' => (string) $this->source,
            'title' => (string) $this->title,
            'language' => (string) $this->language,
            'content_type' => $this->content_type,
            'intent' => $this->intent,
            'primary_keyword' => $this->primary_keyword,
            'secondary_keywords' => is_array($this->secondary_keywords) ? $this->secondary_keywords : [],
            'audience' => $this->audience,
            'audience_details' => $this->audience_details,
            'target_audience' => $this->target_audience,
            'funnel_stage' => $this->funnel_stage,
            'search_intent' => $this->search_intent,
            'notes' => $this->notes,
            'client_refs' => is_array($this->client_refs) ? $this->client_refs : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
