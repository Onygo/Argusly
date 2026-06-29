<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingExecutionApproval;
use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionAuditLog;
use App\Models\AgenticMarketingExecutionFeedback;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticOpportunityExecutionContinuityService
{
    /**
     * @return array<string,mixed>
     */
    public function inspect(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing(['objective', 'content']);

        $canonical = $this->safeLinkedCanonicalOpportunity($opportunity, $linkBlockedReasons);
        $actionIds = AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id);
        $pipelineIds = AgenticMarketingExecutionPipeline::query()
            ->where('opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id);

        $actionsByStatus = $this->groupByStatus(AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id));
        $actionRunsByStatus = $this->groupByStatus($this->actionRunQuery($opportunity, $actionIds));
        $pipelinesByStatus = $this->groupByStatus(AgenticMarketingExecutionPipeline::query()
            ->where('opportunity_id', $opportunity->id));
        $assetsByTypeStatus = $this->assetsByTypeStatus($opportunity, $pipelineIds);
        $approvalsByStatus = $this->groupByStatus($this->pipelineLocalQuery(
            AgenticMarketingExecutionApproval::query(),
            $pipelineIds
        ));
        $feedbackCount = $this->pipelineLocalQuery(AgenticMarketingExecutionFeedback::query(), $pipelineIds)->count();
        $executionAuditLogCount = $this->pipelineLocalQuery(AgenticMarketingExecutionAuditLog::query(), $pipelineIds)->count();
        $agenticAuditLogCount = AgenticMarketingAuditLog::query()
            ->where(function (Builder $query) use ($opportunity, $actionIds): void {
                $query->where('opportunity_id', $opportunity->id)
                    ->orWhere(function (Builder $actionQuery) use ($actionIds): void {
                        if ($actionIds->isEmpty()) {
                            $actionQuery->whereRaw('1 = 0');

                            return;
                        }

                        $actionQuery->whereIn('action_id', $actionIds->all());
                    });
            })
            ->count();

        $counts = [
            'actions' => array_sum($actionsByStatus),
            'action_runs' => array_sum($actionRunsByStatus),
            'execution_pipelines' => array_sum($pipelinesByStatus),
            'execution_assets' => array_sum(array_map('intval', array_column($assetsByTypeStatus, 'count'))),
            'approvals' => array_sum($approvalsByStatus),
            'feedback' => $feedbackCount,
            'audit_logs' => $executionAuditLogCount + $agenticAuditLogCount,
        ];

        $payloadRequirements = $this->payloadRequirements($opportunity);
        $missingExecutionPayloadFields = collect($payloadRequirements)
            ->filter(fn (array $row): bool => (bool) $row['required'] && ! (bool) $row['available'])
            ->pluck('field')
            ->values()
            ->all();
        $canonicalFieldsAvailable = $this->canonicalFieldsAvailable($canonical);
        $missingCanonicalFields = $this->missingCanonicalFields($canonicalFieldsAvailable);
        $legacyOnlyDependencies = $this->legacyOnlyDependencies($counts, $pipelineIds);
        $blockedReasons = $this->blockedReasons(
            $canonical,
            $linkBlockedReasons,
            $counts,
            $missingExecutionPayloadFields,
            $legacyOnlyDependencies
        );

        return [
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'workspace_id' => $this->stringValue($opportunity->objective?->workspace_id)
                ?: $this->stringValue(data_get($opportunity->payload, 'workspace_id')),
            'site_id' => $this->stringValue($opportunity->objective?->client_site_id)
                ?: $this->stringValue(data_get($opportunity->payload, 'client_site_id'))
                ?: $this->stringValue(data_get($opportunity->payload, 'signals.client_site_id')),
            'content_id' => $this->stringValue($opportunity->content_id)
                ?: $this->stringValue(data_get($opportunity->payload, 'content_id')),
            'detector_key' => $this->detectorKey($opportunity),
            'status' => (string) $opportunity->status,
            'actions_count_by_status' => $actionsByStatus,
            'action_runs_count_by_status' => $actionRunsByStatus,
            'execution_pipelines_count_by_status' => $pipelinesByStatus,
            'execution_assets_count_by_type_status' => $assetsByTypeStatus,
            'approvals_count_by_status' => $approvalsByStatus,
            'feedback_count' => $feedbackCount,
            'execution_audit_log_count' => $executionAuditLogCount,
            'agentic_audit_log_count' => $agenticAuditLogCount,
            'audit_log_count' => $counts['audit_logs'],
            'generated_references' => $this->generatedReferences($opportunity, $pipelineIds),
            'payload_fields_required_by_execution_asset_generator' => $payloadRequirements,
            'missing_execution_payload_fields' => $missingExecutionPayloadFields,
            'canonical_fields_available' => $canonicalFieldsAvailable,
            'missing_canonical_fields' => $missingCanonicalFields,
            'legacy_only_execution_dependencies' => $legacyOnlyDependencies,
            'safe_additive_metadata_targets' => $this->safeAdditiveMetadataTargets($canonical),
            'blocked_reasons' => $blockedReasons,
            'blocked' => $blockedReasons !== [],
            'route_parent_dependency_samples' => $this->routeParentDependencySamples($opportunity, $actionIds, $pipelineIds),
            'recommended_future_migration_path' => $this->recommendedFutureMigrationPath($canonical, $blockedReasons, $counts),
            'continuity_rules' => [
                'legacy_agentic_opportunity_id_remains_execution_fk_authority' => true,
                'canonical_opportunity_id_additive_metadata_only' => true,
                'execution_pipeline_route_ids_remain_legacy_agentic_opportunity_ids' => true,
                'existing_action_payloads_must_not_be_rewritten' => true,
                'existing_execution_assets_must_not_be_rewritten' => true,
                'approval_and_feedback_records_remain_pipeline_local' => true,
                'historical_rollback_snapshots_remain_untouched' => true,
            ],
            'counts' => $counts,
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
     * @return array<string,int>
     */
    private function groupByStatus(Builder $query): array
    {
        return $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Collection<int,string>  $actionIds
     */
    private function actionRunQuery(AgenticMarketingOpportunity $opportunity, Collection $actionIds): Builder
    {
        return AgenticActionRun::query()
            ->where(function (Builder $query) use ($opportunity, $actionIds): void {
                $query->where('opportunity_id', $opportunity->id);

                if ($actionIds->isNotEmpty()) {
                    $query->orWhereIn('action_id', $actionIds->all());
                }
            });
    }

    /**
     * @param  Collection<int,string>  $pipelineIds
     */
    private function pipelineLocalQuery(Builder $query, Collection $pipelineIds): Builder
    {
        if ($pipelineIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('pipeline_id', $pipelineIds->all());
    }

    /**
     * @param  Collection<int,string>  $pipelineIds
     * @return array<int,array{type:string,status:string,count:int}>
     */
    private function assetsByTypeStatus(AgenticMarketingOpportunity $opportunity, Collection $pipelineIds): array
    {
        return AgenticMarketingExecutionAsset::query()
            ->where(function (Builder $query) use ($opportunity, $pipelineIds): void {
                $query->where('opportunity_id', $opportunity->id);

                if ($pipelineIds->isNotEmpty()) {
                    $query->orWhereIn('pipeline_id', $pipelineIds->all());
                }
            })
            ->selectRaw('type, status, COUNT(*) as aggregate')
            ->groupBy('type', 'status')
            ->orderBy('type')
            ->orderBy('status')
            ->get()
            ->map(fn (AgenticMarketingExecutionAsset $row): array => [
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,string>  $pipelineIds
     * @return array<string,array<int,array<string,string|null>>>
     */
    private function generatedReferences(AgenticMarketingOpportunity $opportunity, Collection $pipelineIds): array
    {
        $assets = AgenticMarketingExecutionAsset::query()
            ->where(function (Builder $query) use ($opportunity, $pipelineIds): void {
                $query->where('opportunity_id', $opportunity->id);

                if ($pipelineIds->isNotEmpty()) {
                    $query->orWhereIn('pipeline_id', $pipelineIds->all());
                }
            })
            ->whereNotNull('assetable_id')
            ->orderBy('created_at')
            ->get();

        $generated = [
            'briefs' => [],
            'drafts' => [],
            'contents' => [],
            'rollback_snapshots' => [],
        ];

        foreach ($assets as $asset) {
            $type = str_contains((string) $asset->assetable_type, 'Brief') ? 'briefs' : (str_contains((string) $asset->assetable_type, 'Draft') ? 'drafts' : null);
            if ($type) {
                $generated[$type][] = [
                    'asset_id' => (string) $asset->id,
                    'asset_type' => (string) $asset->type,
                    'model' => (string) $asset->assetable_type,
                    'id' => (string) $asset->assetable_id,
                    'legacy_agentic_opportunity_id' => (string) $asset->opportunity_id,
                ];
            }
        }

        if ($opportunity->content_id) {
            $generated['contents'][] = [
                'source' => 'agentic_marketing_opportunities.content_id',
                'id' => (string) $opportunity->content_id,
                'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            ];
        }

        $rollbackSnapshots = AgenticMarketingExecutionPipeline::query()
            ->whereIn('id', $pipelineIds->all())
            ->whereNotNull('rollback_snapshot')
            ->orderBy('created_at')
            ->get(['id', 'opportunity_id', 'rollback_snapshot']);

        foreach ($rollbackSnapshots as $pipeline) {
            $generated['rollback_snapshots'][] = [
                'pipeline_id' => (string) $pipeline->id,
                'legacy_agentic_opportunity_id' => (string) data_get($pipeline->rollback_snapshot, 'opportunity.id', $pipeline->opportunity_id),
                'content_id' => $this->stringValue(data_get($pipeline->rollback_snapshot, 'content.id')),
            ];
        }

        return $generated;
    }

    /**
     * @return array<int,array{field:string,required:bool,available:bool,source:string|null}>
     */
    private function payloadRequirements(AgenticMarketingOpportunity $opportunity): array
    {
        $payload = (array) ($opportunity->payload ?? []);
        $objective = $opportunity->objective;

        return [
            $this->requirement('workspace_id', true, $objective?->workspace_id ?: data_get($payload, 'workspace_id'), $objective?->workspace_id ? 'objective.workspace_id' : 'payload.workspace_id'),
            $this->requirement('client_site_id', true, $objective?->client_site_id ?: data_get($payload, 'client_site_id'), $objective?->client_site_id ? 'objective.client_site_id' : 'payload.client_site_id'),
            $this->requirement('title', true, $opportunity->title, 'agentic_marketing_opportunities.title'),
            $this->requirement('topic_or_primary_keyword', true, data_get($payload, 'topic') ?: data_get($payload, 'primary_keyword') ?: $opportunity->title, data_get($payload, 'topic') ? 'payload.topic' : (data_get($payload, 'primary_keyword') ? 'payload.primary_keyword' : 'agentic_marketing_opportunities.title')),
            $this->requirement('summary_or_reasoning', true, data_get($payload, 'why_this_matters') ?: data_get($payload, 'reasoning') ?: data_get($payload, 'reason') ?: data_get($payload, 'score_explanation.summary'), 'payload.reasoning'),
            $this->requirement('locale', false, $objective?->locale ?: data_get($payload, 'locale'), $objective?->locale ? 'objective.locale' : 'payload.locale'),
            $this->requirement('audience', false, data_get($payload, 'target_audience') ?: $objective?->audience, data_get($payload, 'target_audience') ? 'payload.target_audience' : 'objective.audience'),
            $this->requirement('angle_or_recommendation', false, data_get($payload, 'angle') ?: data_get($payload, 'recommendation'), data_get($payload, 'angle') ? 'payload.angle' : 'payload.recommendation'),
            $this->requirement('suggested_cta', false, data_get($payload, 'suggested_cta'), 'payload.suggested_cta'),
            $this->requirement('suggested_schema', false, data_get($payload, 'suggested_schema'), 'payload.suggested_schema'),
            $this->requirement('content_id', false, $opportunity->content_id ?: data_get($payload, 'content_id'), $opportunity->content_id ? 'agentic_marketing_opportunities.content_id' : 'payload.content_id'),
        ];
    }

    private function requirement(string $field, bool $required, mixed $value, ?string $source): array
    {
        return [
            'field' => $field,
            'required' => $required,
            'available' => $this->stringValue($value) !== null,
            'source' => $this->stringValue($value) !== null ? $source : null,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function canonicalFieldsAvailable(?Opportunity $canonical): array
    {
        return [
            'id' => $canonical !== null,
            'workspace_id' => $this->stringValue($canonical?->workspace_id) !== null,
            'client_site_id' => $this->stringValue($canonical?->client_site_id) !== null,
            'content_id' => $this->stringValue($canonical?->content_id) !== null,
            'title' => $this->stringValue($canonical?->title) !== null,
            'topic' => $this->stringValue($canonical?->topic) !== null,
            'summary' => $this->stringValue($canonical?->summary) !== null,
            'category' => $this->stringValue($canonical?->category?->value ?? $canonical?->category) !== null,
            'status' => $this->stringValue($canonical?->status?->value ?? $canonical?->status) !== null,
            'priority_score' => $canonical?->priority_score !== null,
            'recommended_actions' => ($canonical?->recommended_actions ?? []) !== [],
            'evidence' => ($canonical?->evidence ?? []) !== [],
            'source_signal_summary' => ($canonical?->source_signal_summary ?? []) !== [],
            'metadata' => ($canonical?->metadata ?? []) !== [],
        ];
    }

    /**
     * @param  array<string,bool>  $available
     * @return array<int,string>
     */
    private function missingCanonicalFields(array $available): array
    {
        return collect($available)
            ->filter(fn (bool $present): bool => ! $present)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,int>  $counts
     * @param  Collection<int,string>  $pipelineIds
     * @return array<int,string>
     */
    private function legacyOnlyDependencies(array $counts, Collection $pipelineIds): array
    {
        $dependencies = [
            'execution_routes.use_agentic_marketing_opportunities_id',
        ];

        if ($counts['actions'] > 0) {
            $dependencies[] = 'agentic_marketing_actions.opportunity_id';
        }

        if ($counts['action_runs'] > 0) {
            $dependencies[] = 'agentic_action_runs.opportunity_id';
            $dependencies[] = 'agentic_action_runs.input_snapshot.opportunity_id';
        }

        if ($counts['execution_pipelines'] > 0) {
            $dependencies[] = 'agentic_marketing_execution_pipelines.opportunity_id';
            $dependencies[] = 'agentic_marketing_execution_pipelines.rollback_snapshot.opportunity.id';
        }

        if ($counts['execution_assets'] > 0) {
            $dependencies[] = 'agentic_marketing_execution_assets.opportunity_id';
            $dependencies[] = 'generated_briefs.client_refs.opportunity_id';
            $dependencies[] = 'generated_drafts.meta.opportunity_id';
        }

        if ($counts['approvals'] > 0 || $counts['feedback'] > 0 || $counts['audit_logs'] > 0 || $pipelineIds->isNotEmpty()) {
            $dependencies[] = 'pipeline_local_approvals_feedback_and_audit_logs.pipeline_id';
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @param  array<int,string>  $linkBlockedReasons
     * @param  array<string,int>  $counts
     * @param  array<int,string>  $missingExecutionPayloadFields
     * @param  array<int,string>  $legacyOnlyDependencies
     * @return array<int,string>
     */
    private function blockedReasons(
        ?Opportunity $canonical,
        array $linkBlockedReasons,
        array $counts,
        array $missingExecutionPayloadFields,
        array $legacyOnlyDependencies
    ): array {
        $blocked = $linkBlockedReasons;

        if (! $canonical) {
            $blocked[] = 'missing_safe_canonical_bridge';
        }

        foreach ($missingExecutionPayloadFields as $field) {
            $blocked[] = 'missing_execution_payload_field:'.$field;
        }

        foreach (['actions', 'action_runs', 'execution_pipelines', 'execution_assets'] as $key) {
            if (($counts[$key] ?? 0) > 0) {
                $blocked[] = 'canonical_parent_only_lookup_would_miss_'.$key;
            }
        }

        if (in_array('agentic_marketing_execution_pipelines.rollback_snapshot.opportunity.id', $legacyOnlyDependencies, true)) {
            $blocked[] = 'historical_rollback_snapshots_reference_legacy_agentic_opportunity_id';
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @return array<int,array{target:string,write_scope:string,note:string}>
     */
    private function safeAdditiveMetadataTargets(?Opportunity $canonical): array
    {
        $scope = $canonical ? 'future_rows_only' : 'after_safe_canonical_bridge_exists';

        return [
            [
                'target' => 'agentic_marketing_execution_pipelines.input.canonical_opportunity_id',
                'write_scope' => $scope,
                'note' => 'Additive context for newly prepared pipelines; existing pipeline parents remain legacy ids.',
            ],
            [
                'target' => 'agentic_marketing_execution_assets.payload.canonical_opportunity_context',
                'write_scope' => $scope,
                'note' => 'Additive context for newly generated assets after payload compatibility is proven.',
            ],
            [
                'target' => 'agentic_action_runs.input_snapshot.canonical_opportunity_id',
                'write_scope' => $scope,
                'note' => 'Additive diagnostic metadata; action ownership remains Agentic.',
            ],
            [
                'target' => 'generated_briefs.client_refs.canonical_opportunity_id',
                'write_scope' => $scope,
                'note' => 'Additive source context for future generated briefs only.',
            ],
            [
                'target' => 'generated_drafts.meta.canonical_opportunity_id',
                'write_scope' => $scope,
                'note' => 'Additive source context for future generated drafts only.',
            ],
        ];
    }

    /**
     * @param  Collection<int,string>  $actionIds
     * @param  Collection<int,string>  $pipelineIds
     * @return array<int,string>
     */
    private function routeParentDependencySamples(
        AgenticMarketingOpportunity $opportunity,
        Collection $actionIds,
        Collection $pipelineIds
    ): array {
        $samples = [
            'GET app.agentic-marketing.opportunities.execution.show uses legacy AgenticMarketingOpportunity id '.$opportunity->id,
            'POST app.agentic-marketing.opportunities.execution.prepare uses legacy AgenticMarketingOpportunity id '.$opportunity->id,
        ];

        foreach ($actionIds->take(3) as $actionId) {
            $samples[] = 'agentic_marketing_actions.'.$actionId.' belongs to legacy opportunity '.$opportunity->id;
        }

        foreach ($pipelineIds->take(3) as $pipelineId) {
            $samples[] = 'agentic_marketing_execution_pipelines.'.$pipelineId.' belongs to legacy opportunity '.$opportunity->id;
        }

        return $samples;
    }

    /**
     * @param  array<int,string>  $blockedReasons
     * @param  array<string,int>  $counts
     */
    private function recommendedFutureMigrationPath(?Opportunity $canonical, array $blockedReasons, array $counts): string
    {
        if (in_array('multiple_canonical_opportunities_linked_to_agentic_row', $blockedReasons, true)) {
            return 'Resolve duplicate canonical bridges before adding any execution metadata or planner migration.';
        }

        if (! $canonical) {
            return 'Create or repair a single safe passive canonical bridge first; keep execution fully legacy-owned.';
        }

        if (($counts['actions'] + $counts['action_runs'] + $counts['execution_pipelines'] + $counts['execution_assets']) > 0) {
            return 'Keep legacy execution FKs authoritative; consider additive canonical metadata for future rows only after payload compatibility tests pass.';
        }

        return 'No existing execution rows were found; a future guarded writer can be designed, but route and parent migration still need an explicit phase.';
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
