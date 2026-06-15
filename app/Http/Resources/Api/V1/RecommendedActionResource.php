<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendedActionResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'source_group' => $this->source_group,
            'action_type' => $this->action_type,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'why_this_matters' => $this->why_this_matters,
            'expected_outcome' => $this->expected_outcome,
            'what_argusly_will_do' => $this->what_argusly_will_do,
            'what_requires_approval' => $this->what_requires_approval,
            'estimated_effort' => $this->estimated_effort,
            'scores' => [
                'priority' => $this->priority_score,
                'priority_label' => $this->priority_label,
                'confidence' => $this->confidence_score,
                'confidence_label' => $this->confidence_label,
                'expected_impact' => $this->expected_impact_score,
                'expected_impact_label' => $this->expected_impact_label,
            ],
            'primary_cta' => [
                'label' => $this->primary_cta_label,
                'url' => $this->primary_cta_url,
            ],
            'source' => [
                'type' => $this->source_type,
                'id' => $this->source_id,
            ],
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
