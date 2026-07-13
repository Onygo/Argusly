<?php

namespace App\Services\BrandGrowthPlanning;

use App\Enums\BrandGrowthAudienceProposalType;
use App\Models\BrandGrowthAudienceProposal;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

class BrandGrowthAudiencePromotionService
{
    public function promote(BrandGrowthAudienceProposal $proposal, User $user): Persona
    {
        $proposal->loadMissing(['plan.workspace', 'persona']);

        if (! $proposal->isApproved()) {
            throw new AuthorizationException('Only approved audience proposals can be promoted to personas.');
        }

        if ($proposal->persona instanceof Persona) {
            return $proposal->persona;
        }

        $workspace = $proposal->plan?->workspace;

        if (! $workspace) {
            throw new RuntimeException('The audience proposal is missing workspace context.');
        }

        $type = $this->personaType($proposal);
        $persona = Persona::query()
            ->where('organization_id', $workspace->organization_id)
            ->where('type', $type)
            ->where('name', $proposal->name)
            ->first();

        if (! $persona instanceof Persona) {
            $persona = Persona::query()->create([
                'organization_id' => $workspace->organization_id,
                'type' => $type,
                'name' => $proposal->name,
                'source_type' => 'brand_growth_plan',
                'source_payload' => [
                    'brand_growth_plan_id' => (string) $proposal->brand_growth_plan_id,
                    'brand_growth_audience_proposal_id' => (string) $proposal->id,
                    'workspace_id' => (string) $workspace->id,
                    'proposal_type' => $proposal->proposal_type?->value ?? $proposal->proposal_type,
                    'proposal_source_type' => $proposal->source_type?->value ?? $proposal->source_type,
                    'source_references' => $proposal->source_references ?? [],
                    'confidence_score' => (float) $proposal->confidence_score,
                ],
                'profile_data' => $this->profileData($proposal),
                'status' => Persona::STATUS_APPROVED,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        $proposal->forceFill([
            'persona_id' => $persona->id,
            'metadata_json' => array_merge($proposal->metadata_json ?? [], [
                'promoted_to_persona_at' => now()->toIso8601String(),
                'promoted_to_persona_by' => $user->id,
            ]),
        ])->save();

        return $persona;
    }

    private function personaType(BrandGrowthAudienceProposal $proposal): string
    {
        $proposalType = $proposal->proposal_type?->value ?? $proposal->proposal_type;
        $role = mb_strtolower((string) ($proposal->buying_committee_role ?: $proposal->role));

        if ($proposalType === BrandGrowthAudienceProposalType::BUYING_COMMITTEE_ROLE->value) {
            return str_contains($role, 'influencer')
                ? Persona::TYPE_INFLUENCER
                : Persona::TYPE_DECISION_MAKER;
        }

        return Persona::TYPE_BUYER;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(BrandGrowthAudienceProposal $proposal): array
    {
        return [
            'role' => $proposal->role,
            'seniority' => $proposal->seniority,
            'department' => $proposal->department,
            'industry' => $proposal->industry,
            'company_size' => $proposal->company_size,
            'responsibilities' => $proposal->responsibilities ?? [],
            'goals' => $proposal->goals ?? [],
            'pain_points' => $proposal->pain_points ?? [],
            'objections' => $proposal->objections ?? [],
            'buying_triggers' => $proposal->buying_triggers ?? [],
            'kpis' => $proposal->kpis ?? [],
            'preferred_content' => $proposal->preferred_content ?? [],
            'buying_stage_relevance' => $proposal->buying_stage_relevance ?? [],
            'buying_committee_role' => $proposal->buying_committee_role,
            'tags' => [
                'industry' => array_values(array_filter([(string) $proposal->industry])),
                'source' => ['brand_growth_plan'],
            ],
            'brand_growth' => [
                'audience_proposal_id' => (string) $proposal->id,
                'plan_id' => (string) $proposal->brand_growth_plan_id,
                'confidence_score' => (float) $proposal->confidence_score,
                'source_references' => $proposal->source_references ?? [],
            ],
        ];
    }
}
