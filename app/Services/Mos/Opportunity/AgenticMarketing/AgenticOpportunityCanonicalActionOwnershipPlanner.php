<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Support\Collection;

class AgenticOpportunityCanonicalActionOwnershipPlanner
{
    public function __construct(
        private readonly AgenticOpportunityActionSignatureService $signatures,
        private readonly AgenticOpportunityExecutionContinuityService $continuity,
        private readonly AgenticOpportunityLifecycleInspectionService $lifecycle,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function plan(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('objective');

        $canonical = $this->safeLinkedCanonicalOpportunity($opportunity, $bridgeBlockedReasons);
        $lifecycle = $this->lifecycle->inspect($opportunity, $canonical);
        $continuity = $this->continuity->inspect($opportunity);
        $actions = $this->actions($opportunity);
        $openActions = $actions->filter(fn (AgenticMarketingAction $action): bool => AgenticMarketingAction::isOpenStatus((string) $action->status));
        $candidateSignatures = $this->candidateSignatures($opportunity, $canonical, $openActions);
        $signatureBlockedReasons = $candidateSignatures
            ->flatMap(fn (array $row): array => $row['signature']['blocked_reasons'])
            ->values()
            ->all();
        $duplicateRisks = $candidateSignatures
            ->filter(fn (array $row): bool => ($row['matching_open_action_ids'] ?? []) !== [])
            ->values()
            ->all();
        $continuityParentGaps = collect($continuity['blocked_reasons'])
            ->filter(fn (string $reason): bool => str_starts_with($reason, 'canonical_parent_only_lookup_would_miss_'))
            ->values()
            ->all();
        $blockedReasons = array_values(array_unique(array_filter(array_merge(
            $bridgeBlockedReasons,
            $canonical ? [] : ['missing_safe_canonical_bridge'],
            $signatureBlockedReasons,
            $continuityParentGaps === [] ? [] : ['phase_3i_canonical_parent_only_lookup_gaps'],
            $continuityParentGaps,
            ($lifecycle['lifecycle_status_ambiguous'] ?? false) ? ['lifecycle_status_ambiguous'] : [],
            $lifecycle['status_conflict'] ? ['lifecycle_status_conflict'] : [],
            $duplicateRisks === [] ? [] : ['canonical_action_would_duplicate_open_legacy_action'],
        ))));

        return [
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'linked_canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'workspace_id' => $this->stringValue($opportunity->objective?->workspace_id),
            'site_id' => $this->stringValue($opportunity->objective?->client_site_id)
                ?: $this->stringValue(data_get($opportunity->payload, 'client_site_id'))
                ?: $this->stringValue(data_get($opportunity->payload, 'signals.client_site_id')),
            'detector_key' => $this->detectorKey($opportunity),
            'agentic_type' => $this->stringValue($opportunity->type),
            'legacy_open_action_count' => $openActions->count(),
            'actions_grouped_by_type_and_status' => $this->actionsGroupedByTypeAndStatus($opportunity),
            'canonical_equivalent_action_signature' => $candidateSignatures->first()['signature'] ?? null,
            'canonical_equivalent_action_signatures' => $candidateSignatures->values()->all(),
            'current_legacy_execution_identity' => [
                'model' => AgenticMarketingOpportunity::class,
                'id' => (string) $opportunity->id,
                'execution_fk_authority' => 'agentic_marketing_opportunities.id',
            ],
            'future_canonical_owner_candidate' => $canonical ? [
                'model' => Opportunity::class,
                'id' => (string) $canonical->id,
                'diagnostic_only' => true,
            ] : null,
            'canonical_action_ownership_blocked' => $blockedReasons !== [],
            'blocked_reasons' => $blockedReasons,
            'proposed_metadata_for_future_action_payloads' => $this->proposedMetadata($opportunity, $canonical, $candidateSignatures),
            'fallback_route' => 'Use the existing AgenticMarketingActionPlanner path with AgenticMarketingOpportunity id '.$opportunity->id.'.',
            'proposed_canonical_source_link' => $canonical ? 'metadata.canonical_opportunity_id='.$canonical->id : null,
            'legacy_execution_route' => 'app.agentic-marketing.opportunities.execution.show / prepare using legacy AgenticMarketingOpportunity id '.$opportunity->id,
            'recommended_migration_path' => $this->recommendedMigrationPath($canonical, $blockedReasons, $lifecycle, $continuity),
            'phase_3i_continuity_blockers' => $continuityParentGaps,
        ];
    }

    private function safeLinkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        if ($linked->count() > 1) {
            $blockedReasons[] = 'multiple_canonical_opportunities_linked_to_agentic_row';

            return null;
        }

        $canonical = $linked->first();
        if (! $canonical) {
            return null;
        }

        $legacyWorkspaceId = $this->stringValue($opportunity->objective?->workspace_id);
        if ($legacyWorkspaceId && (string) $canonical->workspace_id !== $legacyWorkspaceId) {
            $blockedReasons[] = 'canonical_bridge_workspace_mismatch';

            return null;
        }

        return $canonical;
    }

    /**
     * @return Collection<int,AgenticMarketingAction>
     */
    private function actions(AgenticMarketingOpportunity $opportunity): Collection
    {
        return AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function candidateSignatures(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical, Collection $openActions): Collection
    {
        return collect($this->candidateActionTypes($opportunity))
            ->map(function (string $actionType) use ($opportunity, $canonical, $openActions): array {
                $signature = $canonical
                    ? $this->signatures->forCanonicalActionCandidate($canonical, $actionType)
                    : $this->signatures->forLegacyOpportunity($opportunity, $actionType);
                $matchingOpenActionIds = $signature['signature']
                    ? $openActions
                        ->filter(fn (AgenticMarketingAction $action): bool => $this->signatures->forAction($action)['signature'] === $signature['signature'])
                        ->map(fn (AgenticMarketingAction $action): string => (string) $action->id)
                        ->values()
                        ->all()
                    : [];

                return [
                    'action_type' => $actionType,
                    'signature' => $signature,
                    'matching_open_action_ids' => $matchingOpenActionIds,
                ];
            });
    }

    /**
     * @return array<int,array{action_type:string,status:string,count:int}>
     */
    private function actionsGroupedByTypeAndStatus(AgenticMarketingOpportunity $opportunity): array
    {
        return AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->selectRaw('action_type, status, COUNT(*) as aggregate')
            ->groupBy('action_type', 'status')
            ->orderBy('action_type')
            ->orderBy('status')
            ->get()
            ->map(fn (AgenticMarketingAction $row): array => [
                'action_type' => (string) $row->action_type,
                'status' => (string) $row->status,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function candidateActionTypes(AgenticMarketingOpportunity $opportunity): array
    {
        $fromExistingActions = AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->pluck('action_type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fromType = match (AgenticMarketingOpportunityType::tryFrom((string) $opportunity->type)) {
            AgenticMarketingOpportunityType::Refresh => [AgenticMarketingActionType::RefreshArticle->value],
            AgenticMarketingOpportunityType::AnswerCoverage => [AgenticMarketingActionType::AddAnswerBlock->value],
            AgenticMarketingOpportunityType::InternalLinks => [AgenticMarketingActionType::ImproveInternalLinks->value],
            AgenticMarketingOpportunityType::LocaleExpansion => [AgenticMarketingActionType::CreateLocaleVariant->value],
            AgenticMarketingOpportunityType::Metadata => [AgenticMarketingActionType::UpdateMeta->value],
            AgenticMarketingOpportunityType::Schema => [AgenticMarketingActionType::AddSchema->value],
            AgenticMarketingOpportunityType::SeoIndexability => [
                AgenticMarketingActionType::UpdateMeta->value,
                AgenticMarketingActionType::AddSchema->value,
            ],
            AgenticMarketingOpportunityType::NewArticle,
            AgenticMarketingOpportunityType::ContentNetwork => [AgenticMarketingActionType::CreateArticle->value],
            AgenticMarketingOpportunityType::AiVisibility => [
                AgenticMarketingActionType::AddAnswerBlock->value,
                AgenticMarketingActionType::UpdateMeta->value,
            ],
            default => [],
        };

        return collect($fromType)
            ->merge($fromExistingActions)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $candidateSignatures
     * @return array<string,mixed>
     */
    private function proposedMetadata(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical, Collection $candidateSignatures): array
    {
        return [
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'legacy_agentic_marketing_opportunity_id' => (string) $opportunity->id,
            'legacy_execution_identity' => 'agentic_marketing_opportunities:'.$opportunity->id,
            'source' => 'mos_phase_3j_diagnostic',
            'action_signature_version' => AgenticOpportunityActionSignatureService::SIGNATURE_VERSION,
            'canonical_equivalent_action_signatures' => $candidateSignatures
                ->pluck('signature.signature')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int,string>  $blockedReasons
     * @param  array<string,mixed>  $lifecycle
     * @param  array<string,mixed>  $continuity
     */
    private function recommendedMigrationPath(?Opportunity $canonical, array $blockedReasons, array $lifecycle, array $continuity): string
    {
        if (! $canonical) {
            return 'Repair or create exactly one safe passive canonical bridge before considering canonical action ownership.';
        }

        if ($blockedReasons !== []) {
            return 'Keep AgenticMarketingOpportunity as action owner; clear lifecycle ambiguity, Phase 3H signature blockers and Phase 3I parent lookup gaps first.';
        }

        if (($continuity['blocked'] ?? false) || ($lifecycle['blocked'] ?? false)) {
            return 'Continue diagnostics until lifecycle and continuity reports are clean in the same dataset.';
        }

        return 'Eligible for a future guarded writer design, but Phase 3J still writes no canonical recommended actions.';
    }

    private function detectorKey(AgenticMarketingOpportunity $opportunity): ?string
    {
        return $this->stringValue(data_get($opportunity->payload, 'detector'))
            ?: $this->stringValue(data_get($opportunity->payload, 'detector_key'))
            ?: $this->stringValue(data_get($opportunity->payload, 'source_detector'))
            ?: $this->stringValue(data_get($opportunity->payload, 'signals.detector'));
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
