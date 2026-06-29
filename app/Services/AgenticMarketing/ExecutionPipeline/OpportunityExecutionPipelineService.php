<?php

namespace App\Services\AgenticMarketing\ExecutionPipeline;

use App\Models\AgenticMarketingExecutionApproval;
use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionFeedback;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\User;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticExecutionCanonicalMetadataResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpportunityExecutionPipelineService
{
    public function __construct(
        private readonly OpportunityExecutionAssetGenerator $assetGenerator,
        private readonly ExecutionAuditLogger $auditLogger,
        private readonly AgenticExecutionCanonicalMetadataResolver $canonicalMetadataResolver,
    ) {}

    public function prepare(AgenticMarketingOpportunity $opportunity, string $mode = 'manual', ?User $actor = null, array $input = []): AgenticMarketingExecutionPipeline
    {
        $opportunity->loadMissing('objective');
        $input = $this->executionInput($opportunity, $input);

        return DB::transaction(function () use ($opportunity, $mode, $actor, $input): AgenticMarketingExecutionPipeline {
            $run = AgenticMarketingRun::query()->create([
                'objective_id' => (string) $opportunity->objective_id,
                'status' => AgenticMarketingRun::STATUS_QUEUED,
                'payload' => ['type' => 'opportunity_execution_pipeline', 'opportunity_id' => (string) $opportunity->id, 'mode' => $mode],
            ]);
            $run->markRunning();

            $pipeline = AgenticMarketingExecutionPipeline::query()->create([
                'organization_id' => $opportunity->objective?->organization_id,
                'objective_id' => (string) $opportunity->objective_id,
                'opportunity_id' => (string) $opportunity->id,
                'run_id' => (string) $run->id,
                'mode' => $mode,
                'status' => 'running',
                'current_stage' => 'asset_generation',
                'approval_status' => 'pending',
                'publishing_readiness' => 'not_ready',
                'input' => $input,
                'rollback_snapshot' => $this->rollbackSnapshot($opportunity),
                'requested_by' => $actor?->id,
                'started_at' => now(),
            ]);
            $this->auditLogger->record($pipeline, 'pipeline.started', after: $pipeline->attributesToArray(), actor: $actor);

            $item = AgenticMarketingRunItem::query()->create([
                'run_id' => (string) $run->id,
                'objective_id' => (string) $opportunity->objective_id,
                'opportunity_id' => (string) $opportunity->id,
                'type' => AgenticMarketingRunItem::TYPE_EXECUTION,
                'name' => 'Prepare opportunity execution',
                'status' => AgenticMarketingRunItem::STATUS_QUEUED,
                'payload' => ['pipeline_id' => (string) $pipeline->id],
            ]);
            $item->markRunning();

            try {
                $assets = $this->assetGenerator->generate($pipeline);
                foreach ($assets as $asset) {
                    $this->createApproval($pipeline, $asset, $actor);
                    $this->auditLogger->record($pipeline, 'asset.generated', after: $asset->attributesToArray(), actor: $actor, asset: $asset);
                }
                $this->createGraphRunItems($run, $pipeline, $assets);

                $pipeline->forceFill($this->readinessPayload($pipeline))->save();
                $item->markCompleted(['assets_count' => count($assets)]);
                $run->markCompleted(['pipeline_id' => (string) $pipeline->id, 'assets_count' => count($assets)]);
                $this->auditLogger->record($pipeline, 'pipeline.prepared', after: $pipeline->fresh()->attributesToArray(), actor: $actor);

                return $pipeline->fresh(['assets', 'approvals', 'auditLogs']);
            } catch (Throwable $exception) {
                $pipeline->forceFill([
                    'status' => 'failed',
                    'current_stage' => 'failed',
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now(),
                ])->save();
                $item->markFailed($exception->getMessage());
                $run->markFailed($exception->getMessage());
                $this->auditLogger->record($pipeline, 'pipeline.failed', after: ['error' => $exception->getMessage()], actor: $actor);

                throw $exception;
            }
        });
    }

    public function approveAsset(AgenticMarketingExecutionAsset $asset, User $actor, ?string $feedback = null): AgenticMarketingExecutionPipeline
    {
        return DB::transaction(function () use ($asset, $actor, $feedback): AgenticMarketingExecutionPipeline {
            $asset = AgenticMarketingExecutionAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $before = $asset->attributesToArray();
            $asset->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_at' => null,
            ])->save();

            AgenticMarketingExecutionApproval::query()
                ->where('asset_id', $asset->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'reviewed_by' => $actor->id,
                    'feedback' => $feedback,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            $pipeline = $asset->pipeline()->lockForUpdate()->firstOrFail();
            if ($feedback) {
                $this->feedback($pipeline, $asset, $actor, $feedback, 'approval_note');
            }
            $pipeline->forceFill($this->readinessPayload($pipeline))->save();
            $this->auditLogger->record($pipeline, 'asset.approved', $before, $asset->fresh()->attributesToArray(), actor: $actor, asset: $asset);

            return $pipeline->fresh(['assets', 'approvals', 'feedback', 'auditLogs']);
        });
    }

    public function rejectAsset(AgenticMarketingExecutionAsset $asset, User $actor, string $feedback): AgenticMarketingExecutionPipeline
    {
        return DB::transaction(function () use ($asset, $actor, $feedback): AgenticMarketingExecutionPipeline {
            $asset = AgenticMarketingExecutionAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $before = $asset->attributesToArray();
            $asset->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
            ])->save();

            AgenticMarketingExecutionApproval::query()
                ->where('asset_id', $asset->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'reviewed_by' => $actor->id,
                    'feedback' => $feedback,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            $pipeline = $asset->pipeline()->lockForUpdate()->firstOrFail();
            $this->feedback($pipeline, $asset, $actor, $feedback, 'rejection_note');
            $pipeline->forceFill($this->readinessPayload($pipeline))->save();
            $this->auditLogger->record($pipeline, 'asset.rejected', $before, $asset->fresh()->attributesToArray(), actor: $actor, asset: $asset);

            return $pipeline->fresh(['assets', 'approvals', 'feedback', 'auditLogs']);
        });
    }

    public function retry(AgenticMarketingExecutionPipeline $pipeline, ?User $actor = null): AgenticMarketingExecutionPipeline
    {
        $pipeline->loadMissing('opportunity');
        $this->auditLogger->record($pipeline, 'pipeline.retry_requested', before: $pipeline->attributesToArray(), actor: $actor);

        return $this->prepare($pipeline->opportunity, $pipeline->mode, $actor, array_merge((array) $pipeline->input, [
            'retry_of_pipeline_id' => (string) $pipeline->id,
        ]));
    }

    public function feedback(AgenticMarketingExecutionPipeline $pipeline, ?AgenticMarketingExecutionAsset $asset, ?User $actor, string $body, string $type = 'review_note'): AgenticMarketingExecutionFeedback
    {
        $feedback = AgenticMarketingExecutionFeedback::query()->create([
            'pipeline_id' => (string) $pipeline->id,
            'asset_id' => $asset?->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
        ]);
        $this->auditLogger->record($pipeline, 'feedback.created', after: $feedback->attributesToArray(), actor: $actor, asset: $asset);

        return $feedback;
    }

    private function createApproval(AgenticMarketingExecutionPipeline $pipeline, AgenticMarketingExecutionAsset $asset, ?User $actor): void
    {
        AgenticMarketingExecutionApproval::query()->create([
            'pipeline_id' => (string) $pipeline->id,
            'asset_id' => (string) $asset->id,
            'status' => 'pending',
            'approval_type' => $this->approvalType($asset->type),
            'requested_role' => $asset->type === 'draft_content' ? 'reviewer' : 'editor',
            'requested_by' => $actor?->id,
        ]);
    }

    /**
     * @param  array<int,AgenticMarketingExecutionAsset>  $assets
     */
    private function createGraphRunItems(AgenticMarketingRun $run, AgenticMarketingExecutionPipeline $pipeline, array $assets): void
    {
        $graphAsset = collect($assets)->first(fn (AgenticMarketingExecutionAsset $asset): bool => $asset->type === 'execution_graph');
        $nodes = (array) data_get($graphAsset?->payload, 'nodes', []);

        foreach ($nodes as $index => $node) {
            $runItem = AgenticMarketingRunItem::query()->create([
                'run_id' => (string) $run->id,
                'objective_id' => (string) $pipeline->objective_id,
                'opportunity_id' => (string) $pipeline->opportunity_id,
                'type' => AgenticMarketingRunItem::TYPE_EXECUTION,
                'name' => (string) ($node['label'] ?? 'Execution graph step'),
                'status' => AgenticMarketingRunItem::STATUS_QUEUED,
                'payload' => [
                    'pipeline_id' => (string) $pipeline->id,
                    'graph_node_id' => (string) ($node['id'] ?? 'step_'.$index),
                    'stage' => (string) ($node['stage'] ?? 'execution'),
                    'depends_on' => (array) ($node['depends_on'] ?? []),
                    'produces' => (array) ($node['produces'] ?? []),
                    'requires_approval' => (bool) ($node['requires_approval'] ?? false),
                ],
            ]);

            if (($node['status'] ?? null) === 'completed') {
                $runItem->markCompleted([
                    'graph_status' => 'completed',
                    'produced_assets' => (array) ($node['produces'] ?? []),
                ]);
            }
        }
    }

    private function rollbackSnapshot(AgenticMarketingOpportunity $opportunity): array
    {
        $opportunity->loadMissing('content');
        $content = $opportunity->content;

        return [
            'opportunity' => [
                'id' => (string) $opportunity->id,
                'title' => $opportunity->title,
                'status' => $opportunity->status,
                'payload' => $opportunity->payload,
            ],
            'content' => $content ? [
                'id' => (string) $content->id,
                'title' => $content->title,
                'seo_title' => $content->seo_title,
                'seo_meta_description' => $content->seo_meta_description,
                'schema_type' => $content->schema_type,
            ] : null,
            'note' => 'Execution preparation does not publish changes; retry creates a new pipeline and keeps this snapshot for audit.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function executionInput(AgenticMarketingOpportunity $opportunity, array $input): array
    {
        unset($input['canonical_opportunity_context']);

        if (! (bool) config('features.mos_agentic_execution_canonical_metadata_writer', false)) {
            return $input;
        }

        $result = $this->canonicalMetadataResolver->resolve($opportunity, 'pipeline');
        if (! (bool) $result['safe']) {
            return $input;
        }

        $input['canonical_opportunity_context'] = $result['metadata'];

        return $input;
    }

    private function readinessPayload(AgenticMarketingExecutionPipeline $pipeline): array
    {
        $pipeline->loadMissing('assets', 'approvals', 'opportunity.objective');
        $assets = $pipeline->assets;
        $pending = $pipeline->approvals->where('status', 'pending')->count();
        $rejected = $pipeline->approvals->where('status', 'rejected')->count();
        $requiredTypes = ['content_brief', 'draft_content', 'metadata', 'schema_markup'];
        $approvedTypes = $assets->where('status', 'approved')->pluck('type')->unique()->all();

        $ready = $pending === 0 && $rejected === 0 && empty(array_diff($requiredTypes, $approvedTypes));
        $readiness = $this->readinessDetails($assets, $pending, $rejected, $requiredTypes, $approvedTypes, $ready);
        $scores = $this->confidenceRiskScores($pipeline, $assets, $pending, $rejected, $ready);

        return [
            'status' => $ready ? 'ready' : 'awaiting_approval',
            'current_stage' => $ready ? 'publishing_readiness' : 'approval',
            'approval_status' => $ready ? 'approved' : ($rejected > 0 ? 'changes_requested' : 'pending'),
            'publishing_readiness' => $ready ? 'ready_for_publishing_pipeline' : 'needs_review',
            'assets_count' => $assets->count(),
            'pending_approvals_count' => $pending,
            'result' => [
                'generated_assets' => $assets->count(),
                'pending_approvals' => $pending,
                'rejected_assets' => $rejected,
                'ready' => $ready,
                'why_this_matters' => $this->whyThisMatters($pipeline),
                'asset_inventory' => $this->assetInventory($assets),
                'execution_timeline' => $this->executionTimeline($pipeline, $assets, $pending, $rejected, $ready),
                'confidence_risk_scores' => $scores,
                'publishing_readiness' => $readiness,
            ],
            'completed_at' => $ready ? now() : $pipeline->completed_at,
        ];
    }

    private function whyThisMatters(AgenticMarketingExecutionPipeline $pipeline): array
    {
        $opportunity = $pipeline->opportunity;
        $payload = (array) ($opportunity?->payload ?? []);
        $signals = (array) data_get($payload, 'signals', []);
        $objective = $opportunity?->objective;

        $triggeredBy = collect([
            data_get($payload, 'reasoning'),
            data_get($payload, 'score_explanation.summary'),
            data_get($payload, 'reason'),
            $opportunity?->title,
        ])->filter()->first();

        $signalLines = collect([
            data_get($signals, 'issues') ? count((array) data_get($signals, 'issues')).' SEO/indexability issue(s) detected' : null,
            data_get($signals, 'competitor_content_count') ? data_get($signals, 'competitor_content_count').' competing content asset(s) detected' : null,
            data_get($signals, 'ai_visibility_score') !== null ? 'AI visibility score: '.data_get($signals, 'ai_visibility_score') : null,
            data_get($signals, 'suggested_link_count') ? data_get($signals, 'suggested_link_count').' internal link opportunity/opportunities' : null,
            data_get($signals, 'gap_type') ? 'Gap type: '.str_replace('_', ' ', (string) data_get($signals, 'gap_type')) : null,
            $opportunity?->priority_score ? 'Opportunity score: '.$opportunity->priority_score.'/100' : null,
        ])->filter()->values()->all();

        if ($signalLines === []) {
            $signalLines = [
                'Opportunity score: '.(int) ($opportunity?->priority_score ?? 0).'/100',
                'Execution can create answer blocks, schema, metadata, internal links, CTAs, and distribution assets from one supervised workflow.',
            ];
        }

        return [
            'summary' => (string) ($triggeredBy ?: 'This opportunity can improve AI visibility and content execution quality.'),
            'business_goal' => (string) ($objective?->goal ?: 'Improve content performance and prepare publish-ready handoff assets.'),
            'icp' => (string) (data_get($payload, 'target_audience') ?: $objective?->audience ?: 'Target audience not specified'),
            'search_intent' => (string) (data_get($payload, 'primary_search_intent') ?: data_get($payload, 'intent') ?: 'Not specified'),
            'ai_visibility_gap' => (string) (data_get($signals, 'gap_type') ?: data_get($payload, 'topic') ?: $opportunity?->type ?: 'Not specified'),
            'triggered_by' => $signalLines,
            'why_now' => $this->whyNow($opportunity?->priority_score ?? 0, $signals),
        ];
    }

    private function whyNow(int $priorityScore, array $signals): string
    {
        if ($priorityScore >= 80) {
            return 'High-priority signal: this should be reviewed before lower-impact content work.';
        }

        if (data_get($signals, 'decay_risk_level') || in_array('not_indexed', (array) data_get($signals, 'issues', []), true)) {
            return 'Time-sensitive signal: indexability or lifecycle decay can compound if it is left unresolved.';
        }

        return 'Useful now because the pipeline can bundle multiple small optimizations into one reviewable execution.';
    }

    private function assetInventory($assets): array
    {
        $labels = [
            'content_brief' => 'Brief',
            'draft_content' => 'Article draft',
            'answer_blocks' => 'Answer blocks',
            'faq_set' => 'FAQ',
            'structured_summary' => 'Structured summary',
            'metadata' => 'Meta title and description',
            'schema_markup' => 'Schema',
            'internal_link_suggestions' => 'Internal links',
            'cta_suggestions' => 'CTA variants',
            'linkedin_post' => 'Social handoff copy',
            'autonomous_campaign_plan' => 'Campaign plan',
            'content_diff_preview' => 'Content diff preview',
            'automation_schedule' => 'Refresh schedule',
            'reviewer_flow' => 'Reviewer flow',
            'campaign_task' => 'External publishing tasks',
            'ai_visibility_scorecard' => 'AI visibility scorecard',
            'strategic_cluster_proposal' => 'Strategic cluster proposal',
            'execution_graph' => 'Execution graph',
        ];

        return $assets
            ->map(fn (AgenticMarketingExecutionAsset $asset): array => [
                'type' => $asset->type,
                'label' => $labels[$asset->type] ?? str_replace('_', ' ', $asset->type),
                'status' => $asset->status,
                'requires_approval' => (bool) $asset->requires_approval,
            ])
            ->values()
            ->all();
    }

    private function executionTimeline(AgenticMarketingExecutionPipeline $pipeline, $assets, int $pending, int $rejected, bool $ready): array
    {
        $has = fn (string $type): bool => $assets->contains(fn (AgenticMarketingExecutionAsset $asset): bool => $asset->type === $type);

        return [
            ['event' => 'opportunity.detected', 'label' => 'Opportunity detected', 'status' => 'completed'],
            ['event' => 'brief.generated', 'label' => 'Brief generated', 'status' => $has('content_brief') ? 'completed' : 'pending'],
            ['event' => 'draft.generated', 'label' => 'Draft generated', 'status' => $has('draft_content') ? 'completed' : 'pending'],
            ['event' => 'seo.generated', 'label' => 'SEO metadata generated', 'status' => $has('metadata') ? 'completed' : 'pending'],
            ['event' => 'internal_links.generated', 'label' => 'Internal links generated', 'status' => $has('internal_link_suggestions') ? 'completed' : 'pending'],
            ['event' => 'schema.generated', 'label' => 'Schema generated', 'status' => $has('schema_markup') ? 'completed' : 'pending'],
            ['event' => 'review.pending', 'label' => 'Human review', 'status' => $rejected > 0 ? 'changes_requested' : ($pending > 0 ? 'pending' : 'completed')],
            ['event' => 'publish.ready', 'label' => 'Publishing readiness', 'status' => $ready ? 'ready' : 'blocked'],
            ['event' => 'social_handoff.pending', 'label' => 'Social handoff', 'status' => $has('linkedin_post') ? 'pending_approval' : 'pending'],
            ['event' => 'refresh.scheduled', 'label' => 'Lifecycle review', 'status' => $has('automation_schedule') ? 'pending_approval' : 'pending'],
        ];
    }

    private function confidenceRiskScores(AgenticMarketingExecutionPipeline $pipeline, $assets, int $pending, int $rejected, bool $ready): array
    {
        $opportunity = $pipeline->opportunity;
        $payload = (array) ($opportunity?->payload ?? []);
        $signals = (array) data_get($payload, 'signals', []);
        $assetTypes = $assets->pluck('type')->unique();
        $requiredCoverage = collect(['answer_blocks', 'schema_markup', 'metadata', 'internal_link_suggestions', 'cta_suggestions'])
            ->filter(fn (string $type): bool => $assetTypes->contains($type))
            ->count();

        $confidence = min(95, 50 + ($requiredCoverage * 7) + min(20, (int) (($opportunity?->priority_score ?? 0) / 5)));
        $publishingRisk = $ready ? 18 : min(90, 35 + ($pending * 3) + ($rejected * 20));
        $hallucinationRisk = $assetTypes->contains('content_diff_preview') && $assetTypes->contains('schema_markup') ? 28 : 45;

        return [
            'confidence_score' => $confidence,
            'ai_visibility_impact' => (int) min(95, max(35, ($opportunity?->priority_score ?? 50) + ($assetTypes->contains('answer_blocks') ? 6 : 0))),
            'seo_impact' => (int) min(95, 45 + ($assetTypes->contains('metadata') ? 12 : 0) + ($assetTypes->contains('schema_markup') ? 12 : 0) + ($assetTypes->contains('internal_link_suggestions') ? 8 : 0)),
            'brand_alignment' => (int) min(95, 62 + ($assetTypes->contains('reviewer_flow') ? 12 : 0)),
            'hallucination_risk' => $hallucinationRisk,
            'publishing_risk' => $publishingRisk,
            'requires_human_validation' => $pending > 0 || $rejected > 0 || ! $ready,
        ];
    }

    private function readinessDetails($assets, int $pending, int $rejected, array $requiredTypes, array $approvedTypes, bool $ready): array
    {
        $missingApprovals = array_values(array_diff($requiredTypes, $approvedTypes));
        $assetTypes = $assets->pluck('type')->unique()->all();
        $issues = [];

        if ($pending > 0) {
            $issues[] = $pending.' asset approval(s) still pending';
        }
        if ($rejected > 0) {
            $issues[] = $rejected.' asset(s) have change requests';
        }
        foreach ($missingApprovals as $type) {
            $issues[] = str_replace('_', ' ', $type).' has not been approved';
        }
        foreach (['schema_markup' => 'No schema generated', 'internal_link_suggestions' => 'No internal links generated', 'cta_suggestions' => 'CTA not generated', 'answer_blocks' => 'Answer blocks not generated'] as $type => $message) {
            if (! in_array($type, $assetTypes, true)) {
                $issues[] = $message;
            }
        }

        return [
            'status' => $ready ? 'ready_for_publishing_pipeline' : 'needs_review',
            'why_not_ready' => $ready ? [] : array_values(array_unique($issues)),
            'next_actions' => $ready
                ? ['Queue content publishing handoff', 'Export approved social copy to the external publishing tool', 'Schedule lifecycle review', 'Monitor AI visibility after publication']
                : ['Review generated assets', 'Approve required brief, draft, metadata, schema, and social handoff assets', 'Resolve change requests before publishing handoff'],
        ];
    }

    private function approvalType(string $assetType): string
    {
        return match ($assetType) {
            'content_brief' => 'brief_approval',
            'draft_content' => 'editorial_review',
            'answer_blocks' => 'answer_block_review',
            'schema_markup' => 'technical_seo_review',
            'automation_schedule' => 'automation_approval',
            default => 'asset_review',
        };
    }
}
