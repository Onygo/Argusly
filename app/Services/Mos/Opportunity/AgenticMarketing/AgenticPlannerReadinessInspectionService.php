<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Support\Collection;

class AgenticPlannerReadinessInspectionService
{
    public const STATUS_LEGACY_ONLY = 'legacy_only';

    public const STATUS_CANONICAL_CONTEXT_AVAILABLE = 'canonical_context_available';

    public const STATUS_METADATA_READY_ONLY = 'metadata_ready_only';

    public const STATUS_PLANNER_CANDIDATE_BLOCKED = 'planner_candidate_blocked';

    public const STATUS_PLANNER_CANDIDATE_READY = 'planner_candidate_ready_for_guarded_experiment';

    public function __construct(
        private readonly AgenticOpportunityActionSignatureService $signatures,
        private readonly AgenticOpportunityExecutionContinuityService $continuity,
        private readonly AgenticOpportunityLifecycleInspectionService $lifecycle,
        private readonly AgenticExecutionCanonicalMetadataResolver $metadata,
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('objective');

        $canonical = $this->safeLinkedCanonicalOpportunity($opportunity, $bridgeBlockedReasons, $linkedCount);
        $mapping = $this->mapping->mapExisting($opportunity);
        $signatures = $this->candidateSignatures($opportunity, $canonical);
        $openActions = $this->openActions($opportunity);
        $duplicateRisks = $this->duplicateRisks($signatures, $openActions);
        $continuity = $this->continuity->inspect($opportunity);
        $lifecycle = $this->lifecycle->inspect($opportunity, $canonical);
        $metadata = $this->metadata->resolve($opportunity, 'planner_readiness');
        $continuityParentBlockers = $this->continuityParentBlockers($continuity);
        $signatureBlockers = $this->signatureBlockers($signatures, $mapping->blockedReasons);
        $lifecycleBlockers = $this->lifecycleBlockers($lifecycle);
        $readinessBlockers = array_values(array_unique(array_filter(array_merge(
            $bridgeBlockedReasons,
            $canonical ? [] : ['missing_safe_canonical_bridge'],
            $signatureBlockers,
            $continuityParentBlockers,
            $lifecycleBlockers,
            $duplicateRisks === [] ? [] : ['canonical_action_would_duplicate_open_legacy_action'],
        ))));
        $readinessStatus = $this->readinessStatus(
            linkedCount: $linkedCount,
            canonical: $canonical,
            bridgeBlockedReasons: $bridgeBlockedReasons,
            readinessBlockers: $readinessBlockers,
            metadataReady: (bool) ($metadata['safe'] ?? false),
            canonicalContextOnlyBlocked: $this->canonicalContextOnlyBlocked($readinessBlockers, $lifecycle),
        );

        return [
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'linked_canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'workspace_id' => $this->stringValue($opportunity->objective?->workspace_id),
            'site_id' => $this->stringValue($opportunity->objective?->client_site_id)
                ?: $this->stringValue(data_get($opportunity->payload, 'client_site_id'))
                ?: $this->stringValue(data_get($opportunity->payload, 'signals.client_site_id')),
            'detector_key' => $mapping->detectorKey,
            'agentic_type' => $this->stringValue($opportunity->type),
            'legacy_priority_score' => (int) $opportunity->priority_score,
            'canonical_priority_score' => $canonical?->priority_score,
            'priority_provenance' => $this->priorityProvenance($opportunity, $canonical),
            'current_planner_eligibility' => $this->currentPlannerEligibility($opportunity),
            'current_legacy_selection_rank_inputs' => $this->legacySelectionRankInputs($opportunity),
            'canonical_selection_candidate_fields' => $this->canonicalCandidateFields($canonical),
            'phase_3h_signature_status' => [
                'safe' => $signatureBlockers === [],
                'blocked_reasons' => $signatureBlockers,
                'signature_version' => AgenticOpportunityActionSignatureService::SIGNATURE_VERSION,
                'candidate_signatures' => $signatures,
            ],
            'phase_3i_continuity_status' => [
                'safe_for_planner_candidate' => $continuityParentBlockers === [],
                'canonical_parent_only_lookup_blockers' => $continuityParentBlockers,
                'blocked_reasons' => $continuity['blocked_reasons'] ?? [],
            ],
            'phase_3j_lifecycle_action_ownership_status' => [
                'safe_for_planner_candidate' => $lifecycleBlockers === [],
                'lifecycle_status_ambiguous' => (bool) ($lifecycle['lifecycle_status_ambiguous'] ?? false),
                'status_conflict' => (bool) ($lifecycle['status_conflict'] ?? false),
                'blocked_reasons' => $lifecycle['blocked_reasons'] ?? [],
                'candidate_mapped_canonical_status' => $lifecycle['candidate_mapped_canonical_status'] ?? [],
            ],
            'phase_3k_metadata_availability_for_future_rows' => [
                'available' => (bool) ($metadata['safe'] ?? false),
                'metadata_version' => AgenticExecutionCanonicalMetadataResolver::METADATA_VERSION,
                'canonical_opportunity_id' => $metadata['canonical_opportunity_id'] ?? null,
                'blocked_reasons' => $metadata['blocked_reasons'] ?? [],
                'traceability_only' => true,
            ],
            'open_legacy_actions_count' => $openActions->count(),
            'duplicate_action_risk' => [
                'risk' => $duplicateRisks !== [],
                'count' => count($duplicateRisks),
                'items' => $duplicateRisks,
            ],
            'readiness_status' => $readinessStatus,
            'readiness_blocked_reasons' => $readinessBlockers,
            'readiness_rules' => [
                'exactly_one_safe_canonical_bridge_required' => true,
                'phase_3h_signature_must_be_safe' => true,
                'phase_3i_canonical_parent_only_lookup_blockers_must_be_absent' => true,
                'phase_3j_lifecycle_must_not_be_ambiguous' => true,
                'open_legacy_action_duplicates_must_be_absent' => true,
                'phase_3k_metadata_is_traceability_only' => true,
            ],
        ];
    }

    private function safeLinkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons, ?int &$linkedCount): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();
        $linkedCount = $linked->count();

        if ($linkedCount > 1) {
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
    private function openActions(AgenticMarketingOpportunity $opportunity): Collection
    {
        return AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->open()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidateSignatures(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical): array
    {
        return collect($this->candidateActionTypes($opportunity))
            ->map(function (string $actionType) use ($opportunity, $canonical): array {
                $signature = $canonical
                    ? $this->signatures->forCanonicalActionCandidate($canonical, $actionType)
                    : $this->signatures->forLegacyOpportunity($opportunity, $actionType);

                return [
                    'action_type' => $actionType,
                    'signature' => $signature,
                    'safe' => ($signature['blocked_reasons'] ?? []) === [] && filled($signature['signature'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function candidateActionTypes(AgenticMarketingOpportunity $opportunity): array
    {
        return match (AgenticMarketingOpportunityType::tryFrom((string) $opportunity->type)) {
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
    }

    /**
     * @param  array<int,array<string,mixed>>  $signatures
     * @param  Collection<int,AgenticMarketingAction>  $openActions
     * @return array<int,array<string,mixed>>
     */
    private function duplicateRisks(array $signatures, Collection $openActions): array
    {
        $risks = [];

        foreach ($signatures as $candidate) {
            $signature = data_get($candidate, 'signature.signature');
            if (! $signature) {
                continue;
            }

            $matchingOpenActionIds = $openActions
                ->filter(fn (AgenticMarketingAction $action): bool => $this->signatures->forAction($action)['signature'] === $signature)
                ->map(fn (AgenticMarketingAction $action): string => (string) $action->id)
                ->values()
                ->all();

            if ($matchingOpenActionIds !== []) {
                $risks[] = [
                    'type' => 'future_canonical_candidate_matches_existing_open_agentic_action',
                    'action_type' => $candidate['action_type'],
                    'signature' => $signature,
                    'action_ids' => $matchingOpenActionIds,
                ];
            }
        }

        return $risks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $signatures
     * @param  array<int,string>  $mappingBlockers
     * @return array<int,string>
     */
    private function signatureBlockers(array $signatures, array $mappingBlockers): array
    {
        $blocked = collect($signatures)
            ->flatMap(fn (array $row): array => (array) data_get($row, 'signature.blocked_reasons', []))
            ->merge($mappingBlockers)
            ->values()
            ->all();

        if ($signatures === []) {
            $blocked[] = 'missing_candidate_action_type';
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @param  array<string,mixed>  $continuity
     * @return array<int,string>
     */
    private function continuityParentBlockers(array $continuity): array
    {
        return collect((array) ($continuity['blocked_reasons'] ?? []))
            ->filter(fn (string $reason): bool => str_starts_with($reason, 'canonical_parent_only_lookup_would_miss_'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $lifecycle
     * @return array<int,string>
     */
    private function lifecycleBlockers(array $lifecycle): array
    {
        $blocked = [];

        if ((bool) ($lifecycle['lifecycle_status_ambiguous'] ?? false)) {
            $blocked[] = 'phase_3j_lifecycle_status_ambiguous';
        }

        if ((bool) ($lifecycle['status_conflict'] ?? false)) {
            $blocked[] = 'phase_3j_lifecycle_status_conflict';
        }

        foreach ((array) ($lifecycle['blocked_reasons'] ?? []) as $reason) {
            if (in_array($reason, [
                'unmapped_agentic_lifecycle_status',
                'canonical_status_conflicts_with_candidate_mapping',
            ], true)) {
                $blocked[] = $reason;
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @param  array<int,string>  $bridgeBlockedReasons
     * @param  array<int,string>  $readinessBlockers
     */
    private function readinessStatus(
        int $linkedCount,
        ?Opportunity $canonical,
        array $bridgeBlockedReasons,
        array $readinessBlockers,
        bool $metadataReady,
        bool $canonicalContextOnlyBlocked,
    ): string {
        if ($linkedCount === 0) {
            return self::STATUS_LEGACY_ONLY;
        }

        if (! $canonical || $bridgeBlockedReasons !== []) {
            return self::STATUS_PLANNER_CANDIDATE_BLOCKED;
        }

        if ($readinessBlockers === []) {
            return self::STATUS_PLANNER_CANDIDATE_READY;
        }

        if ($metadataReady && $canonicalContextOnlyBlocked) {
            return self::STATUS_METADATA_READY_ONLY;
        }

        if ($canonicalContextOnlyBlocked) {
            return self::STATUS_CANONICAL_CONTEXT_AVAILABLE;
        }

        return self::STATUS_PLANNER_CANDIDATE_BLOCKED;
    }

    /**
     * @param  array<int,string>  $readinessBlockers
     * @param  array<string,mixed>  $lifecycle
     */
    private function canonicalContextOnlyBlocked(array $readinessBlockers, array $lifecycle): bool
    {
        $allowedReadinessBlockers = ['phase_3j_lifecycle_status_ambiguous'];
        $allowedLifecycleReasons = [
            'agentic_open_is_executable_input',
            'candidate_canonical_status_is_not_single_valued',
        ];

        return $readinessBlockers !== []
            && array_values(array_diff($readinessBlockers, $allowedReadinessBlockers)) === []
            && array_values(array_diff((array) ($lifecycle['blocked_reasons'] ?? []), $allowedLifecycleReasons)) === [];
    }

    /**
     * @return array<string,mixed>
     */
    private function priorityProvenance(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical): array
    {
        return [
            'legacy_source' => 'agentic_marketing_opportunities.priority_score',
            'canonical_source' => $canonical ? 'opportunities.priority_score' : null,
            'legacy_score_explanation' => data_get($opportunity->payload, 'score_explanation'),
            'canonical_score_breakdown' => $canonical?->score_breakdown,
            'difference' => $canonical ? round((float) $canonical->priority_score - (float) $opportunity->priority_score, 4) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function currentPlannerEligibility(AgenticMarketingOpportunity $opportunity): array
    {
        return [
            'eligible' => (string) $opportunity->status === AgenticMarketingOpportunityStatus::Open->value,
            'reason' => (string) $opportunity->status === AgenticMarketingOpportunityStatus::Open->value
                ? 'current_planner_selects_open_agentic_marketing_opportunities'
                : 'current_planner_filters_to_open_agentic_marketing_opportunities',
            'planner' => AgenticMarketingActionPlanner::class,
            'selection_authority' => 'agentic_marketing_opportunities',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacySelectionRankInputs(AgenticMarketingOpportunity $opportunity): array
    {
        $rank = AgenticMarketingOpportunity::query()
            ->where('objective_id', $opportunity->objective_id)
            ->where('status', AgenticMarketingOpportunityStatus::Open->value)
            ->where(function ($query) use ($opportunity): void {
                $query->where('priority_score', '>', (int) $opportunity->priority_score)
                    ->orWhere(function ($tieQuery) use ($opportunity): void {
                        $tieQuery->where('priority_score', (int) $opportunity->priority_score)
                            ->where('id', '<', $opportunity->id);
                    });
            })
            ->count() + 1;

        return [
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'status_filter' => AgenticMarketingOpportunityStatus::Open->value,
            'priority_score' => (int) $opportunity->priority_score,
            'order_by' => ['priority_score desc', 'id asc'],
            'rank_within_objective_open_scope' => $rank,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function canonicalCandidateFields(?Opportunity $canonical): ?array
    {
        if (! $canonical) {
            return null;
        }

        return [
            'id' => (string) $canonical->id,
            'workspace_id' => $this->stringValue($canonical->workspace_id),
            'client_site_id' => $this->stringValue($canonical->client_site_id),
            'status' => $this->stringValue($canonical->status?->value ?? $canonical->status),
            'category' => $this->stringValue($canonical->category?->value ?? $canonical->category),
            'title' => $canonical->title,
            'topic' => $canonical->topic,
            'priority_score' => $canonical->priority_score,
            'confidence_score' => $canonical->confidence_score,
            'impact_score' => $canonical->impact_score,
            'urgency_score' => $canonical->urgency_score,
            'effort_score' => $canonical->effort_score,
            'detector_key' => data_get($canonical->metadata, 'detector_key') ?: data_get($canonical->source_signal_summary, 'detector_key'),
            'agentic_type' => data_get($canonical->metadata, 'agentic_type') ?: data_get($canonical->source_signal_summary, 'opportunity_type'),
        ];
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
