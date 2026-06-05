<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'client_site_id' => $this->client_site_id,
            'content_destination_id' => $this->content_destination_id,
            'status' => (string) $this->status,
            'pages_crawled' => (int) $this->pages_crawled,
            'issue_counts' => is_array($this->issue_counts) ? $this->issue_counts : [],
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
