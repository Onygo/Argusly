<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticCanonicalPlannerExperimentService
{
    public function __construct(
        private readonly AgenticPlannerReadinessInspectionService $readiness,
        private readonly AgenticCanonicalPlannerDryRunAdapter $dryRun,
        private readonly AgenticOpportunityActionSignatureService $signatures,
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
        private readonly AgenticMarketingActionPlanner $planner,
    ) {}

    /**
     * @param  array{status?:string|null,detector?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function compare(AgenticMarketingObjective $objective, array $filters = []): array
    {
        $rows = $this->opportunities($objective, $filters);
        $readinessRows = $rows
            ->map(fn (AgenticMarketingOpportunity $opportunity): array => $this->readiness->inspect($opportunity))
            ->values();

        $legacyOrder = $rows
            ->filter(fn (AgenticMarketingOpportunity $opportunity): bool => (string) $opportunity->status === AgenticMarketingOpportunityStatus::Open->value)
            ->sortBy([
                ['priority_score', 'desc'],
                ['id', 'asc'],
            ])
            ->values()
            ->map(fn (AgenticMarketingOpportunity $opportunity, int $index): array => $this->legacyCandidate($opportunity, $index + 1))
            ->values();

        $readinessByLegacyId = $readinessRows->keyBy('legacy_agentic_opportunity_id');
        $canonicalOrder = $rows
            ->filter(fn (AgenticMarketingOpportunity $opportunity): bool => ($readinessByLegacyId[(string) $opportunity->id]['readiness_status'] ?? null) === AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
            ->sort(function (AgenticMarketingOpportunity $left, AgenticMarketingOpportunity $right) use ($readinessByLegacyId): int {
                $leftPriority = (float) ($readinessByLegacyId[(string) $left->id]['canonical_priority_score'] ?? 0);
                $rightPriority = (float) ($readinessByLegacyId[(string) $right->id]['canonical_priority_score'] ?? 0);

                return $rightPriority <=> $leftPriority ?: strcmp((string) $left->id, (string) $right->id);
            })
            ->values()
            ->map(function (AgenticMarketingOpportunity $opportunity, int $index) use ($readinessByLegacyId): array {
                $readiness = $readinessByLegacyId[(string) $opportunity->id];
                $actions = collect($this->dryRun->proposeForReadyRow($opportunity, $readiness))
                    ->map(fn (AgenticCanonicalPlannerDryRunAction $action): array => $action->toArray())
                    ->values();

                return [
                    'rank' => $index + 1,
                    'legacy_opportunity_id' => (string) $opportunity->id,
                    'canonical_opportunity_id' => (string) $readiness['linked_canonical_opportunity_id'],
                    'legacy_priority_score' => (int) $opportunity->priority_score,
                    'canonical_priority_score' => $readiness['canonical_priority_score'],
                    'readiness_status' => $readiness['readiness_status'],
                    'action_types' => $actions->pluck('action_type')->values()->all(),
                    'dry_run_actions' => $actions->all(),
                    'expected_noop' => $actions->isEmpty() || $actions->contains(fn (array $action): bool => (bool) $action['expected_noop']),
                ];
            })
            ->values();

        $excluded = $readinessRows
            ->reject(fn (array $row): bool => $row['readiness_status'] === AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
            ->map(fn (array $row): array => [
                'legacy_opportunity_id' => $row['legacy_agentic_opportunity_id'],
                'canonical_opportunity_id' => $row['linked_canonical_opportunity_id'],
                'readiness_status' => $row['readiness_status'],
                'blocked_reasons' => $row['readiness_blocked_reasons'],
                'duplicate_action_risk' => (bool) data_get($row, 'duplicate_action_risk.risk'),
                'signature_blocked' => data_get($row, 'phase_3h_signature_status.blocked_reasons', []) !== [],
                'continuity_blocked' => data_get($row, 'phase_3i_continuity_status.canonical_parent_only_lookup_blockers', []) !== [],
                'lifecycle_blocked' => data_get($row, 'phase_3j_lifecycle_action_ownership_status.blocked_reasons', []) !== [],
            ])
            ->values();

        $priorityDifferences = $this->priorityOrderDifferences($legacyOrder, $canonicalOrder);
        $signatureEquivalence = $this->signatureEquivalence($rows, $readinessByLegacyId);
        $summary = [
            'inspected_objectives' => 1,
            'inspected_rows' => $rows->count(),
            'legacy_candidate_count' => $legacyOrder->count(),
            'canonical_ready_candidate_count' => $canonicalOrder->count(),
            'blocked_candidate_count' => $excluded->count(),
            'priority_order_difference_count' => count($priorityDifferences),
            'duplicate_risk_count' => $readinessRows->filter(fn (array $row): bool => (bool) data_get($row, 'duplicate_action_risk.risk'))->count(),
            'signature_blocker_count' => $readinessRows->filter(fn (array $row): bool => data_get($row, 'phase_3h_signature_status.blocked_reasons', []) !== [])->count(),
            'continuity_blocker_count' => $readinessRows->filter(fn (array $row): bool => data_get($row, 'phase_3i_continuity_status.canonical_parent_only_lookup_blockers', []) !== [])->count(),
            'lifecycle_blocker_count' => $readinessRows->filter(fn (array $row): bool => (bool) data_get($row, 'phase_3j_lifecycle_action_ownership_status.lifecycle_status_ambiguous') || (bool) data_get($row, 'phase_3j_lifecycle_action_ownership_status.status_conflict'))->count(),
            'action_signature_equivalent_count' => $signatureEquivalence->where('equivalent', true)->count(),
            'action_signature_mismatch_count' => $signatureEquivalence->where('equivalent', false)->count(),
            'expected_noop_count' => $canonicalOrder->filter(fn (array $row): bool => (bool) $row['expected_noop'])->count(),
            'feature_enabled' => (bool) config('features.mos_agentic_planner_canonical_experiment', false),
        ];
        $summary['recommendation'] = $this->recommendation($summary);

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'summary' => $summary,
            'legacy_order' => $legacyOrder->all(),
            'canonical_experiment_order' => $canonicalOrder->all(),
            'excluded_rows' => $excluded->all(),
            'priority_order_differences' => $priorityDifferences,
            'action_signature_equivalence' => $signatureEquivalence->values()->all(),
            'readiness_rows' => $readinessRows->all(),
        ];
    }

    /**
     * @param  array{status?:string|null,detector?:string|null}  $filters
     * @return Collection<int,AgenticMarketingOpportunity>
     */
    private function opportunities(AgenticMarketingObjective $objective, array $filters): Collection
    {
        $detector = trim((string) ($filters['detector'] ?? ''));

        return $objective->opportunities()
            ->with(['objective', 'content'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id')
            ->get()
            ->filter(function (AgenticMarketingOpportunity $opportunity) use ($detector): bool {
                return $detector === '' || $this->mapping->mapExisting($opportunity)->detectorKey === $detector;
            })
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyCandidate(AgenticMarketingOpportunity $opportunity, int $rank): array
    {
        $readOnlyPlans = collect($this->planner->previewPlannedActions($opportunity));

        return [
            'rank' => $rank,
            'legacy_opportunity_id' => (string) $opportunity->id,
            'priority_score' => (int) $opportunity->priority_score,
            'status' => (string) $opportunity->status,
            'action_types' => $readOnlyPlans->pluck('action_type')->values()->all(),
            'expected_noop' => $readOnlyPlans->isEmpty() || $readOnlyPlans->contains(fn (array $plan): bool => ! (bool) data_get($plan, 'prerequisites.met', false)),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $legacyOrder
     * @param  Collection<int,array<string,mixed>>  $canonicalOrder
     * @return array<int,array<string,mixed>>
     */
    private function priorityOrderDifferences(Collection $legacyOrder, Collection $canonicalOrder): array
    {
        $legacyRanks = $legacyOrder->pluck('rank', 'legacy_opportunity_id');

        return $canonicalOrder
            ->filter(fn (array $row): bool => $legacyRanks->has($row['legacy_opportunity_id']) && (int) $legacyRanks[$row['legacy_opportunity_id']] !== (int) $row['rank'])
            ->map(fn (array $row): array => [
                'legacy_opportunity_id' => $row['legacy_opportunity_id'],
                'legacy_rank' => (int) $legacyRanks[$row['legacy_opportunity_id']],
                'canonical_rank' => (int) $row['rank'],
                'legacy_priority_score' => $row['legacy_priority_score'],
                'canonical_priority_score' => $row['canonical_priority_score'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AgenticMarketingOpportunity>  $rows
     * @param  Collection<string,array<string,mixed>>  $readinessByLegacyId
     * @return Collection<int,array<string,mixed>>
     */
    private function signatureEquivalence(Collection $rows, Collection $readinessByLegacyId): Collection
    {
        return $rows
            ->flatMap(function (AgenticMarketingOpportunity $opportunity) use ($readinessByLegacyId): array {
                $readiness = $readinessByLegacyId[(string) $opportunity->id] ?? null;
                if (! $readiness || ! $readiness['linked_canonical_opportunity_id']) {
                    return [];
                }

                return collect((array) data_get($readiness, 'phase_3h_signature_status.candidate_signatures', []))
                    ->map(function (array $candidate) use ($opportunity): array {
                        $actionType = (string) ($candidate['action_type'] ?? '');
                        $legacySignature = $this->signatures->forLegacyOpportunity($opportunity, $actionType);

                        return [
                            'legacy_opportunity_id' => (string) $opportunity->id,
                            'action_type' => $actionType,
                            'legacy_signature' => $legacySignature['signature'] ?? null,
                            'canonical_signature' => data_get($candidate, 'signature.signature'),
                            'equivalent' => filled($legacySignature['signature'] ?? null)
                                && ($legacySignature['signature'] ?? null) === data_get($candidate, 'signature.signature'),
                            'blocked_reasons' => array_values(array_unique(array_merge(
                                (array) ($legacySignature['blocked_reasons'] ?? []),
                                (array) data_get($candidate, 'signature.blocked_reasons', []),
                            ))),
                        ];
                    })
                    ->all();
            })
            ->values();
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function recommendation(array $summary): string
    {
        if ((int) $summary['canonical_ready_candidate_count'] === 0 && (int) $summary['blocked_candidate_count'] === 0) {
            return 'keep legacy';
        }

        if ((int) $summary['duplicate_risk_count'] > 0
            || (int) $summary['signature_blocker_count'] > 0
            || (int) $summary['continuity_blocker_count'] > 0
            || (int) $summary['lifecycle_blocker_count'] > 0
            || (int) $summary['action_signature_mismatch_count'] > 0) {
            return 'blocked';
        }

        if ((int) $summary['canonical_ready_candidate_count'] > 0) {
            return 'safe for scoped dry-run';
        }

        return 'keep legacy';
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
