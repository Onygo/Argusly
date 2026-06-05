<?php

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitorInsightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'title' => $this->title,
            'topic' => $this->topic,
            'query_intent' => $this->query_intent,
            'funnel_stage' => $this->funnel_stage,
            'recommended_format' => $this->recommended_format,
            'scores' => [
                'priority' => (float) $this->priority_score,
                'confidence' => (float) $this->confidence_score,
                'impact' => (float) $this->impact_score,
                'effort' => (float) $this->effort_score,
            ],
            'attackable_angle' => $this->attackable_angle,
            'reason' => $this->reason,
            'competitor_evidence' => $this->competitor_evidence ?? [],
            'publishlayer_coverage' => $this->publishlayer_coverage ?? [],
            'normalized_payload' => $this->normalized_payload ?? [],
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
        ];
    }
}
