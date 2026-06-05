<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'organization_id' => $this->organization_id ? (int) $this->organization_id : null,
            'workspace_id' => (string) $this->workspace_id,
            'client_site_id' => $this->client_site_id ? (string) $this->client_site_id : null,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'objective' => $this->objective,
            'status' => (string) ($this->status?->value ?? $this->status),
            'approval_status' => (string) ($this->approval_status?->value ?? $this->approval_status),
            'planning' => [
                'agentic_marketing_objective_id' => $this->agentic_marketing_objective_id ? (string) $this->agentic_marketing_objective_id : null,
                'campaign_cluster_id' => $this->campaign_cluster_id ? (string) $this->campaign_cluster_id : null,
                'tone_profile_id' => $this->tone_profile_id ? (string) $this->tone_profile_id : null,
                'cta_preset_id' => $this->cta_preset_id ? (string) $this->cta_preset_id : null,
                'audience' => $this->audience ?? [],
                'goals' => $this->goals ?? [],
                'kpis' => $this->kpis ?? [],
                'channel_mix' => $this->channel_mix ?? [],
                'ai_context' => $this->ai_planning_context ?? [],
                'optimization_signals' => $this->optimization_signals ?? [],
                'internal_linking_strategy' => $this->internal_linking_strategy ?? [],
            ],
            'schedule' => [
                'planned_start_date' => $this->planned_start_date?->toDateString(),
                'planned_end_date' => $this->planned_end_date?->toDateString(),
                'scheduled_start_at' => $this->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $this->scheduled_end_at?->toIso8601String(),
            ],
            'relationships' => [
                'contents_count' => $this->whenCounted('contents'),
                'distribution_plans_count' => $this->whenCounted('distributionPlans'),
                'contents' => CampaignContentResource::collection($this->whenLoaded('contents')),
                'distribution_plans' => CampaignDistributionPlanResource::collection($this->whenLoaded('distributionPlans')),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'last_planned_at' => $this->last_planned_at?->toIso8601String(),
                'submitted_for_approval_at' => $this->submitted_for_approval_at?->toIso8601String(),
                'approved_at' => $this->approved_at?->toIso8601String(),
            ],
        ];
    }
}
