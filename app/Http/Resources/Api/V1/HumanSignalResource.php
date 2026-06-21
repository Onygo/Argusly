<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HumanSignalResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'organization_id' => $this->organization_id,
            'workspace_id' => (string) $this->workspace_id,
            'site_id' => $this->site_id ? (string) $this->site_id : null,
            'type' => $this->type?->value ?? $this->type,
            'title' => (string) $this->title,
            'observation' => (string) $this->observation,
            'impact' => (string) $this->impact,
            'confidence_score' => (float) $this->confidence_score,
            'status' => (string) $this->status,
            'detected_at' => optional($this->detected_at)->toIso8601String(),
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'metadata' => $this->metadata_json ?: [],
            'evidence' => $this->whenLoaded('evidence', fn () => $this->evidence->map(fn ($evidence): array => [
                'id' => (string) $evidence->id,
                'source_type' => (string) $evidence->source_type,
                'source_id' => $evidence->source_id,
                'title' => $evidence->title,
                'summary' => $evidence->summary,
                'weight' => (float) $evidence->weight,
                'metrics' => $evidence->metrics_json ?: [],
            ])->values()),
            'insights' => $this->whenLoaded('insights', fn () => $this->insights->map(fn ($insight): array => [
                'id' => (string) $insight->id,
                'title' => (string) $insight->title,
                'insight' => (string) $insight->insight,
                'recommended_action' => (string) $insight->recommended_action,
                'quality_score' => (float) $insight->quality_score,
            ])->values()),
        ];
    }
}
