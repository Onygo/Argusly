<?php

namespace App\Services\BrandGrowthPlanning\Analyzers;

use App\Enums\BrandGrowthAudienceProposalType;
use App\Enums\BrandGrowthAudienceSourceType;
use App\Enums\BrandGrowthFindingType;
use App\Services\BrandGrowthPlanning\BrandGrowthAnalyzerResult;
use Illuminate\Support\Arr;

class AudienceIntelligenceAnalyzer implements BrandGrowthAnalyzer
{
    public function analyze(array $context): BrandGrowthAnalyzerResult
    {
        $brandAudience = (array) data_get($context, 'brand_intelligence.audience', []);
        $icps = $this->strings(data_get($brandAudience, 'icps', []));
        $personaNames = $this->strings(data_get($brandAudience, 'personas', []));
        $buyerRoles = $this->strings(data_get($brandAudience, 'buyer_roles', []));
        $approvedPersonaCount = (int) data_get($context, 'personas.approved_count', 0);
        $findings = [];
        $proposals = [];
        $missing = [];
        $assumptions = [];

        foreach (array_slice($icps, 0, 3) as $icp) {
            $proposals[] = $this->proposal(
                type: BrandGrowthAudienceProposalType::AUDIENCE->value,
                name: $icp,
                role: null,
                confidence: 72,
                sourceReferences: [
                    'company_intelligence_profile_ids' => array_filter([(string) data_get($context, 'brand_intelligence.sources.company_intelligence_profile_id')]),
                ],
                metadata: ['source_field' => 'brand_intelligence.audience.icps']
            );
        }

        foreach (array_slice($personaNames, 0, 4) as $persona) {
            $proposals[] = $this->proposal(
                type: BrandGrowthAudienceProposalType::PERSONA->value,
                name: $persona,
                role: $persona,
                confidence: 68,
                sourceReferences: [
                    'company_intelligence_profile_ids' => array_filter([(string) data_get($context, 'brand_intelligence.sources.company_intelligence_profile_id')]),
                ],
                metadata: ['source_field' => 'brand_intelligence.audience.personas']
            );
        }

        foreach (array_slice($buyerRoles, 0, 5) as $role) {
            $proposals[] = $this->proposal(
                type: BrandGrowthAudienceProposalType::BUYING_COMMITTEE_ROLE->value,
                name: $role,
                role: $role,
                confidence: 66,
                sourceReferences: [
                    'company_intelligence_profile_ids' => array_filter([(string) data_get($context, 'brand_intelligence.sources.company_intelligence_profile_id')]),
                ],
                metadata: ['source_field' => 'brand_intelligence.audience.buyer_roles']
            );
        }

        if ($approvedPersonaCount === 0 && ($icps !== [] || $personaNames !== [] || $buyerRoles !== [])) {
            $findings[] = [
                'type' => BrandGrowthFindingType::PERSONA_GAP->value,
                'title' => 'Audience intelligence exists but canonical personas are not approved',
                'description' => 'Brand Intelligence contains audience signals, but the workspace has no approved personas for governed targeting.',
                'rationale' => 'Inferred audiences should be reviewed before execution agents use them as canonical strategy.',
                'impact_score' => 74,
                'urgency_score' => 66,
                'confidence_score' => 78,
                'affected_audience' => $icps[0] ?? $personaNames[0] ?? null,
                'recommended_action' => 'Review inferred audience proposals and approve the roles that should guide future opportunities and briefs.',
                'source_references' => [
                    'company_intelligence_profile_ids' => array_filter([(string) data_get($context, 'brand_intelligence.sources.company_intelligence_profile_id')]),
                ],
                'source_summary' => [
                    'approved_personas' => $approvedPersonaCount,
                    'inferred_icps' => count($icps),
                    'inferred_personas' => count($personaNames),
                    'buyer_roles' => count($buyerRoles),
                ],
            ];
        }

        if ($buyerRoles === []) {
            $missing[] = 'Buying committee roles are not defined in Brand Intelligence.';
        }

        if ($icps === [] && $personaNames === []) {
            $missing[] = 'No ICP or persona signals are available.';
            $assumptions[] = 'The first plan can still highlight content and visibility gaps, but audience prioritization will be low-confidence.';
        }

        $underrepresentedRoles = $this->underrepresentedRoles($buyerRoles, $context);
        if ($underrepresentedRoles !== []) {
            $findings[] = [
                'type' => BrandGrowthFindingType::AUDIENCE_OPPORTUNITY->value,
                'title' => 'Priority buyer roles are underrepresented in content',
                'description' => 'Some buyer roles from Brand Intelligence do not appear in sampled owned content titles.',
                'rationale' => 'Role-specific content helps make the brand more relevant and memorable for the buying committee.',
                'impact_score' => 70,
                'urgency_score' => 58,
                'confidence_score' => 64,
                'affected_audience' => implode(', ', array_slice($underrepresentedRoles, 0, 3)),
                'recommended_action' => 'Create or refresh role-specific assets for the underrepresented buying roles.',
                'source_references' => [
                    'content_ids' => collect(data_get($context, 'content.items', []))->pluck('id')->take(10)->values()->all(),
                    'company_intelligence_profile_ids' => array_filter([(string) data_get($context, 'brand_intelligence.sources.company_intelligence_profile_id')]),
                ],
                'source_summary' => [
                    'roles_checked' => $buyerRoles,
                    'underrepresented_roles' => $underrepresentedRoles,
                    'sampled_content' => (int) data_get($context, 'content.sampled', 0),
                ],
            ];
        }

        return new BrandGrowthAnalyzerResult(
            summary: 'Audience Intelligence reviewed ICPs, personas, buying roles, and existing persona governance.',
            findings: $findings,
            audienceProposals: $proposals,
            confidence: $missing === [] ? 72 : 58,
            assumptions: $assumptions,
            missingData: $missing,
            sourcesUsed: ['brand_intelligence', 'personas', 'content_inventory'],
            sourcesNotAvailable: $missing,
            recommendedActions: ['Review inferred audiences before using them as approved strategy.'],
        );
    }

    /**
     * @return array<int, string>
     */
    private function underrepresentedRoles(array $roles, array $context): array
    {
        if ($roles === []) {
            return [];
        }

        $titles = collect(data_get($context, 'content.items', []))
            ->pluck('title')
            ->map(fn (mixed $title): string => mb_strtolower((string) $title))
            ->implode(' ');

        if ($titles === '') {
            return $roles;
        }

        return collect($roles)
            ->filter(fn (string $role): bool => ! str_contains($titles, mb_strtolower($role)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(string $type, string $name, ?string $role, float $confidence, array $sourceReferences, array $metadata): array
    {
        return [
            'proposal_type' => $type,
            'source_type' => BrandGrowthAudienceSourceType::INFERRED->value,
            'name' => $name,
            'role' => $role,
            'confidence_score' => $confidence,
            'source_references' => $sourceReferences,
            'metadata_json' => $metadata,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->flatten()
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->values()
            ->all();
    }
}
