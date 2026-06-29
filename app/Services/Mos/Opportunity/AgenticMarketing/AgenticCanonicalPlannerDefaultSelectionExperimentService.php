<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgenticCanonicalPlannerDefaultSelectionExperimentService
{
    public const METADATA_VERSION = 'agentic-planner-canonical-default-selection:v1';

    public function __construct(
        private readonly AgenticCanonicalPlannerDefaultSelectionPreviewService $preview,
        private readonly AgenticMarketingActionPlanner $planner,
    ) {}

    /**
     * @param  array{site?:string|null,detector?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function run(AgenticMarketingObjective $objective, int $limit, array $filters = [], bool $apply = false): array
    {
        $limit = max(0, $limit);
        $preview = $this->preview->preview($objective, [
            'site' => $filters['site'] ?? null,
            'detector' => $filters['detector'] ?? null,
            'limit' => $limit,
        ]);

        $selectedRows = collect((array) ($preview['canonical_proposed_default_order'] ?? []))
            ->take($limit)
            ->values();
        $legacyRows = collect((array) ($preview['legacy_candidate_order'] ?? []))
            ->take($limit)
            ->values();
        $gate = $this->gate($preview, $legacyRows, $selectedRows, $limit);
        $resolved = $this->resolveSelectedRows($objective, $selectedRows);
        $blockedRows = array_merge($gate['blocked_rows'], $resolved['blocked_rows']);
        $selectedRowsByLegacyId = $selectedRows->keyBy(fn (array $row): string => (string) ($row['legacy_opportunity_id'] ?? ''));
        $readinessByLegacyId = collect((array) ($preview['phase_3l_readiness_rows'] ?? []))
            ->keyBy('legacy_agentic_opportunity_id');
        $signatureEquivalence = collect((array) ($preview['phase_3h_signature_equivalence'] ?? []))
            ->groupBy(fn (array $row): string => (string) ($row['legacy_opportunity_id'] ?? '').':'.(string) ($row['action_type'] ?? ''));

        $dryRunActions = [];
        $createdActionIds = [];
        $reusedActionIds = [];
        $plannedActionIds = [];
        $metadataSamples = [];
        $signatureSamples = collect((array) ($preview['phase_3h_signature_equivalence'] ?? []))
            ->pluck('canonical_signature')
            ->filter()
            ->map(fn (mixed $signature): string => (string) $signature)
            ->values()
            ->all();

        foreach ($resolved['opportunities'] as $opportunity) {
            $legacyId = (string) $opportunity->id;
            $row = (array) $selectedRowsByLegacyId->get($legacyId, []);

            foreach ($this->wouldPlan($opportunity) as $plan) {
                $dryRunActions[] = $plan;
            }

            if (! $apply || $blockedRows !== []) {
                continue;
            }

            $before = AgenticMarketingAction::query()
                ->where('opportunity_id', $opportunity->id)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $plannerResult = $this->planner->planForOpportunity($opportunity);
            $plannedIds = collect((array) ($plannerResult['action_ids'] ?? []))
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            $created = array_values(array_diff($plannedIds, $before));
            $reused = array_values(array_intersect($plannedIds, $before));
            $createdActionIds = array_values(array_unique(array_merge($createdActionIds, $created)));
            $reusedActionIds = array_values(array_unique(array_merge($reusedActionIds, $reused)));
            $plannedActionIds = array_values(array_unique(array_merge($plannedActionIds, $plannedIds)));

            foreach ($plannedIds as $actionId) {
                $metadata = $this->attachExperimentMetadata(
                    actionId: $actionId,
                    row: $row,
                    readiness: (array) $readinessByLegacyId->get($legacyId, []),
                    signatureEquivalence: $signatureEquivalence,
                    preview: $preview
                );

                if ($metadata !== null) {
                    $metadataSamples[] = $metadata;
                    if (($metadata['phase_3m_signature'] ?? null) !== null) {
                        $signatureSamples[] = (string) $metadata['phase_3m_signature'];
                    }
                }
            }
        }

        $skippedCount = $selectedRows->count() - count($resolved['opportunities']);

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'apply' => $apply,
            'feature_enabled' => (bool) config('features.mos_agentic_planner_canonical_default_selection_experiment', false),
            'phase_3q_preview_status' => (string) ($preview['default_selection_preview_status'] ?? 'unknown'),
            'phase_3p_recommendation' => (string) ($preview['phase_3p_shadow_recommendation'] ?? data_get($preview, 'apply_safety.phase_3p_recommendation', 'unknown')),
            'selected_canonical_opportunity_ids' => $selectedRows
                ->pluck('canonical_opportunity_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->filter()
                ->values()
                ->all(),
            'resolved_legacy_agentic_opportunity_ids' => collect($resolved['opportunities'])
                ->map(fn (AgenticMarketingOpportunity $opportunity): string => (string) $opportunity->id)
                ->values()
                ->all(),
            'dry_run_actions' => $dryRunActions,
            'created_action_ids' => $createdActionIds,
            'reused_action_ids' => $reusedActionIds,
            'created_or_reused_action_ids' => array_values(array_unique(array_merge($createdActionIds, $reusedActionIds))),
            'signature_samples' => array_values(array_unique(array_slice($signatureSamples, 0, 10))),
            'metadata_samples' => array_slice($metadataSamples, 0, 5),
            'blocked_rows' => $blockedRows,
            'summary' => [
                'legacy_candidate_count' => (int) data_get($preview, 'summary.legacy_candidate_count', $legacyRows->count()),
                'canonical_selected_count' => $selectedRows->count(),
                'created_action_count' => count($createdActionIds),
                'reused_action_count' => count($reusedActionIds),
                'blocked_count' => count($blockedRows),
                'skipped_count' => max(0, $skippedCount),
                'would_create_action_count' => collect($dryRunActions)->where('would', 'create')->count(),
                'would_reuse_action_count' => collect($dryRunActions)->where('would', 'reuse')->count(),
                'recommendation' => $this->recommendation($apply, $blockedRows),
            ],
            'rollback_notes' => [
                'Disable ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT.',
                'Existing payload.default_selection_experiment metadata is trace context only and can be ignored.',
                'Actions remain legacy-owned through AgenticMarketingOpportunity ids; no metadata removal writer is introduced in Phase 3R.',
            ],
            'phase_3q_preview_report' => $preview,
        ];
    }

    /**
     * @param  array<string,mixed>  $preview
     * @param  Collection<int,array<string,mixed>>  $legacyRows
     * @param  Collection<int,array<string,mixed>>  $selectedRows
     * @return array{blocked_rows:array<int,array<string,mixed>>}
     */
    private function gate(array $preview, Collection $legacyRows, Collection $selectedRows, int $limit): array
    {
        $blocked = [];
        $safety = (array) ($preview['apply_safety'] ?? []);

        if (! (bool) config('features.mos_agentic_planner_canonical_default_selection_experiment', false)) {
            $blocked[] = $this->blocker('feature_flag_disabled');
        }

        if ($limit < 1) {
            $blocked[] = $this->blocker('limit_must_be_explicit_positive_integer');
        }

        if (($preview['default_selection_preview_status'] ?? null) !== AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE) {
            $blocked[] = $this->blocker('phase_3q_preview_status_is_not_preview_safe');
        }

        $phase3p = (string) ($preview['phase_3p_shadow_recommendation'] ?? ($safety['phase_3p_recommendation'] ?? ''));
        if ($phase3p !== 'continue shadow') {
            $blocked[] = $this->blocker('phase_3p_recommendation_is_not_continue_shadow');
        }

        $auditRows = collect((array) ($preview['phase_3o_audit_rows'] ?? []));
        if (collect((array) ($preview['phase_3o_risky_rows'] ?? []))->isNotEmpty()
            || $auditRows->reject(fn (array $row): bool => in_array($row['audit_status'] ?? null, [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))->isNotEmpty()) {
            $blocked[] = $this->blocker('phase_3o_audit_has_risky_rows');
        }

        $readinessByLegacyId = collect((array) ($preview['phase_3l_readiness_rows'] ?? []))->keyBy('legacy_agentic_opportunity_id');
        foreach ($selectedRows as $row) {
            $legacyId = (string) ($row['legacy_opportunity_id'] ?? '');
            $readiness = (array) $readinessByLegacyId->get($legacyId, []);
            if (($row['readiness_status'] ?? $readiness['readiness_status'] ?? null) !== AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY) {
                $blocked[] = $this->blocker('canonical_proposed_candidate_is_not_phase_3l_ready', $legacyId, (string) ($row['canonical_opportunity_id'] ?? ''));
            }
        }

        $signatureRows = collect((array) ($preview['phase_3h_signature_equivalence'] ?? []));
        if ((int) ($safety['phase_3h_signature_risk_count'] ?? 0) > 0
            || $signatureRows->contains(fn (array $row): bool => ! (bool) ($row['equivalent'] ?? true) || ((array) ($row['blocked_reasons'] ?? [])) !== [])) {
            $blocked[] = $this->blocker('phase_3h_signatures_do_not_match');
        }

        foreach ([
            'phase_3i_continuity_risk_count' => 'phase_3i_continuity_has_blockers',
            'phase_3j_lifecycle_risk_count' => 'phase_3j_lifecycle_has_ambiguity_or_conflict',
            'duplicate_open_action_risk_count' => 'duplicate_open_action_risk_exists',
        ] as $key => $reason) {
            if ((int) ($safety[$key] ?? 0) > 0) {
                $blocked[] = $this->blocker($reason);
            }
        }

        if (! (bool) ($safety['canonical_coverage_sufficient'] ?? false)) {
            $blocked[] = $this->blocker('canonical_coverage_is_not_sufficient');
        }

        $legacyIds = $legacyRows->pluck('legacy_opportunity_id')->map(fn (mixed $id): string => (string) $id)->all();
        $selectedLegacyIds = $selectedRows->pluck('legacy_opportunity_id')->map(fn (mixed $id): string => (string) $id)->all();
        if (! (bool) ($safety['exact_order_match'] ?? false) || $legacyIds !== $selectedLegacyIds) {
            $blocked[] = $this->blocker('canonical_proposed_order_does_not_exactly_match_legacy_order_for_scope');
        }

        return ['blocked_rows' => $this->uniqueBlockers($blocked)];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $selectedRows
     * @return array{opportunities:array<int,AgenticMarketingOpportunity>,blocked_rows:array<int,array<string,mixed>>}
     */
    private function resolveSelectedRows(AgenticMarketingObjective $objective, Collection $selectedRows): array
    {
        $opportunities = [];
        $blocked = [];

        foreach ($selectedRows as $row) {
            $legacyId = (string) ($row['legacy_opportunity_id'] ?? '');
            $canonicalId = (string) ($row['canonical_opportunity_id'] ?? '');

            $canonical = $canonicalId !== '' ? Opportunity::query()->whereKey($canonicalId)->first() : null;
            if (! $canonical) {
                $blocked[] = $this->blocker('missing_canonical_opportunity', $legacyId, $canonicalId);

                continue;
            }

            if ((string) $canonical->agentic_marketing_opportunity_id !== $legacyId) {
                $blocked[] = $this->blocker('canonical_bridge_does_not_resolve_to_selected_legacy_opportunity', $legacyId, $canonicalId);

                continue;
            }

            $opportunity = AgenticMarketingOpportunity::query()
                ->with(['objective', 'content'])
                ->whereKey($legacyId)
                ->where('objective_id', $objective->id)
                ->first();

            if (! $opportunity) {
                $blocked[] = $this->blocker('missing_legacy_agentic_marketing_opportunity', $legacyId, $canonicalId);

                continue;
            }

            $opportunities[] = $opportunity;
        }

        return ['opportunities' => $opportunities, 'blocked_rows' => $blocked];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function wouldPlan(AgenticMarketingOpportunity $opportunity): array
    {
        return collect($this->planner->previewPlannedActions($opportunity))
            ->filter(fn (array $plan): bool => (bool) data_get($plan, 'prerequisites.met', false))
            ->map(function (array $plan) use ($opportunity): array {
                $existing = AgenticMarketingAction::query()
                    ->where('opportunity_id', $opportunity->id)
                    ->where('action_type', (string) ($plan['action_type'] ?? ''))
                    ->open()
                    ->orderBy('created_at')
                    ->first();

                return [
                    'legacy_agentic_marketing_opportunity_id' => (string) $opportunity->id,
                    'action_type' => (string) ($plan['action_type'] ?? ''),
                    'would' => $existing ? 'reuse' : 'create',
                    'existing_action_id' => $existing?->id ? (string) $existing->id : null,
                    'approval_required' => (bool) ($plan['approval_required'] ?? false),
                    'estimated_credits' => (int) ($plan['estimated_credits'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $readiness
     * @param  Collection<int|string,Collection<int,array<string,mixed>>>  $signatureEquivalence
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>|null
     */
    private function attachExperimentMetadata(string $actionId, array $row, array $readiness, Collection $signatureEquivalence, array $preview): ?array
    {
        $action = AgenticMarketingAction::query()->with('objective')->whereKey($actionId)->first();
        if (! $action) {
            return null;
        }

        $signature = collect($signatureEquivalence->get((string) $row['legacy_opportunity_id'].':'.(string) $action->action_type, []))
            ->pluck('canonical_signature')
            ->filter()
            ->first();

        $metadata = [
            'version' => self::METADATA_VERSION,
            'canonical_opportunity_id' => (string) ($row['canonical_opportunity_id'] ?? ''),
            'legacy_agentic_marketing_opportunity_id' => (string) ($row['legacy_opportunity_id'] ?? $action->opportunity_id),
            'objective_id' => (string) $action->objective_id,
            'workspace_id' => $this->stringValue($action->objective?->workspace_id) ?: ($readiness['workspace_id'] ?? null),
            'selection_source' => 'canonical_default_selection_experiment',
            'phase_3q_preview_status' => AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE,
            'phase_3p_recommendation' => 'continue_shadow',
            'phase_3m_signature' => $signature,
            'phase_3l_readiness_status' => (string) ($readiness['readiness_status'] ?? $row['readiness_status'] ?? ''),
            'applied_at' => now()->toIso8601String(),
            'applied_by' => 'command',
        ];

        $payload = (array) ($action->payload ?? []);
        $payload['default_selection_experiment'] = $metadata;

        DB::table($action->getTable())
            ->where('id', $action->id)
            ->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocker(string $reason, ?string $legacyId = null, ?string $canonicalId = null): array
    {
        return [
            'legacy_opportunity_id' => $legacyId,
            'canonical_opportunity_id' => $canonicalId,
            'reasons' => [$reason],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocked
     * @return array<int,array<string,mixed>>
     */
    private function uniqueBlockers(array $blocked): array
    {
        return collect($blocked)
            ->unique(fn (array $row): string => implode('|', [
                (string) ($row['legacy_opportunity_id'] ?? ''),
                (string) ($row['canonical_opportunity_id'] ?? ''),
                implode(',', (array) ($row['reasons'] ?? [])),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockedRows
     */
    private function recommendation(bool $apply, array $blockedRows): string
    {
        if ($blockedRows !== []) {
            return 'blocked';
        }

        return $apply
            ? 'applied through legacy planner ownership'
            : 'dry-run eligible for guarded apply with feature flag enabled';
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
