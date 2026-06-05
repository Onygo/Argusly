<?php

namespace App\Services\AgenticMarketing;

use App\Agents\Support\AgentRunStatus;
use App\Enums\LearningRecommendationType;
use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgentWorkflowRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingWorkflowOverride;
use App\Models\AgenticMarketingWorkflowRule;
use App\Models\Campaign;
use App\Models\LearningRecommendation;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignPlanning\CampaignPlannerService;
use App\Services\LearningOptimization\LearningOptimizationEngine;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutonomousMarketingWorkflowEngine
{
    public function __construct(
        private readonly LearningOptimizationEngine $learningEngine,
        private readonly OpportunityIntelligenceEngine $opportunityEngine,
        private readonly AgenticMarketingOpportunityDetectionService $detectionService,
        private readonly AgenticMarketingActionPlanner $actionPlanner,
        private readonly AgenticApprovalGate $approvalGate,
        private readonly CampaignPlannerService $campaignPlanner,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public function run(Workspace $workspace, string $triggerType = 'signal_monitor', array $input = [], ?User $actor = null): AgentWorkflowRun
    {
        $rule = $this->ruleFor($workspace, $triggerType, $input);
        $overrides = $this->activeOverrides($workspace);

        $run = AgentWorkflowRun::query()->create([
            'workflow_key' => 'agentic_marketing_autonomous_orchestration',
            'trigger_type' => $triggerType,
            'trigger_source' => (string) ($input['trigger_source'] ?? 'system'),
            'status' => AgentRunStatus::RUNNING,
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'site_id' => $input['client_site_id'] ?? $input['site_id'] ?? null,
            'user_id' => $actor?->id,
            'input_payload' => [
                'input' => $input,
                'rule' => $rule->attributesToArray(),
                'policy' => $this->policySnapshot($workspace, $rule, $overrides),
            ],
            'started_at' => now(),
        ]);

        $this->audit($run, $workspace, 'workflow.started', null, $run->input_payload);

        if ($this->isPaused($overrides)) {
            $output = [
                'status' => 'skipped',
                'reason' => 'An active human override paused autonomous marketing workflows for this workspace.',
                'overrides' => $overrides->values()->all(),
            ];

            $run->forceFill([
                'status' => AgentRunStatus::SKIPPED,
                'summary' => $output['reason'],
                'output_payload' => $output,
                'finished_at' => now(),
            ])->save();
            $this->audit($run, $workspace, 'workflow.skipped', null, $output);

            return $run->refresh();
        }

        try {
            $output = DB::transaction(function () use ($workspace, $rule, $run, $input, $overrides, $actor): array {
                $signalSummary = $this->monitorSignals($workspace, $input);
                $agenticDetection = $this->detectAgenticOpportunities($workspace, $input);
                $actionSummary = $rule->generate_content_drafts
                    ? $this->planActions($workspace, $run, $rule, $overrides, $actor)
                    : ['created' => 0, 'gated_actions' => [], 'queued_action_ids' => []];
                $campaignSummary = $rule->generate_campaign_proposals
                    ? $this->generateCampaignProposal($workspace, $rule, $input, $actor)
                    : ['created' => false, 'campaign_id' => null, 'reason' => 'Campaign proposals disabled by rule.'];

                return [
                    'signal_monitoring' => $signalSummary,
                    'agentic_opportunity_detection' => $agenticDetection,
                    'campaign_proposal' => $campaignSummary,
                    'actions' => $actionSummary,
                    'approval_checkpoints' => $this->approvalCheckpoints($actionSummary, $campaignSummary, $rule, $overrides),
                    'human_overrides' => $overrides->values()->all(),
                    'safety' => [
                        'fully_autonomous_publishing_enabled' => false,
                        'publishing_requires_explicit_customer_approval' => true,
                        'auto_queue_approved_actions' => (bool) $rule->auto_queue_approved_actions,
                    ],
                ];
            });

            $run->forceFill([
                'status' => AgentRunStatus::SUCCESS,
                'summary' => $this->summary($output),
                'output_payload' => $output,
                'finished_at' => now(),
            ])->save();
            $rule->forceFill(['last_run_at' => now()])->save();
            $this->audit($run, $workspace, 'workflow.completed', null, $output);

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => AgentRunStatus::FAILED,
                'summary' => 'Autonomous marketing workflow failed before execution.',
                'error_message' => $exception->getMessage(),
                'output_payload' => ['error' => $exception->getMessage()],
                'finished_at' => now(),
            ])->save();
            $this->audit($run, $workspace, 'workflow.failed', null, ['error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function monitorSignals(Workspace $workspace, array $input): array
    {
        $learning = $this->learningEngine->run($workspace);
        $opportunities = $this->opportunityEngine->run($workspace);

        return [
            'learning' => $learning,
            'opportunity_intelligence' => $opportunities,
            'triggered_by' => $input['trigger_source'] ?? 'system',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function detectAgenticOpportunities(Workspace $workspace, array $input): array
    {
        $objectiveId = $input['objective_id'] ?? null;
        if ($objectiveId) {
            return $this->detectionService->detect((string) $objectiveId);
        }

        $summary = ['objectives' => 0, 'created' => 0, 'reused' => 0, 'failed' => 0, 'runs' => []];

        AgenticMarketingObjective::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->each(function (AgenticMarketingObjective $objective) use (&$summary): void {
                $result = $this->detectionService->detectForObjective($objective);
                $summary['objectives']++;
                $summary['created'] += (int) ($result['created'] ?? 0);
                $summary['reused'] += (int) ($result['reused'] ?? 0);
                $summary['failed'] += ($result['status'] ?? null) === 'failed' ? 1 : 0;
                $summary['runs'][] = $result;
            });

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function planActions(Workspace $workspace, AgentWorkflowRun $run, AgenticMarketingWorkflowRule $rule, Collection $overrides, ?User $actor): array
    {
        $actions = collect();
        $planned = ['created' => 0, 'reused' => 0, 'skipped' => 0];

        AgenticMarketingOpportunity::query()
            ->whereHas('objective', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('status', 'open')
            ->orderByDesc('priority_score')
            ->limit((int) $rule->maximum_actions_per_run)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use (&$planned, $actions): void {
                $result = $this->actionPlanner->planForOpportunity($opportunity);
                $planned['created'] += (int) $result['created'];
                $planned['reused'] += (int) $result['reused'];
                $planned['skipped'] += (int) $result['skipped'];
                $actions->push(...AgenticMarketingAction::query()->whereIn('id', $result['action_ids'])->get());
            });

        $gated = [];
        $queued = [];

        foreach ($actions->unique('id') as $action) {
            if ($this->actionBlocked($action, $overrides, $rule)) {
                $gated[] = $this->checkpoint($action, 'blocked', 'Human override or workflow rule blocked this action.', null);
                continue;
            }

            $confidence = $this->actionConfidence($action);
            $gate = $this->approvalGate->forMarketingAction($action, [
                'has_customer_approval' => false,
                'priority_score' => $confidence,
            ]);
            if ($this->forceApproval($overrides) || $confidence < (int) $rule->minimum_confidence_score) {
                $gate['decision'] = AgenticApprovalGate::DECISION_REQUIRES_APPROVAL;
                $gate['reason'] = $confidence < (int) $rule->minimum_confidence_score
                    ? 'Confidence is below the workflow threshold.'
                    : 'Human override forces approval for this workflow.';
            }

            $payload = (array) ($action->payload ?? []);
            $action->forceFill([
                'payload' => array_replace_recursive($payload, [
                    'automation' => [
                        'workflow_run_id' => (string) $run->id,
                        'confidence_score' => $confidence,
                        'approval_gate' => $gate,
                        'explainability' => $this->actionExplanation($action),
                    ],
                ]),
            ])->save();

            if (
                (bool) $rule->auto_queue_approved_actions
                && ($gate['decision'] ?? null) === AgenticApprovalGate::DECISION_ALLOWED
                && ! $this->isPublicationLike($action)
            ) {
                $action->forceFill(['status' => AgenticMarketingAction::STATUS_APPROVED, 'approved_at' => now()])->save();
                ExecuteAgenticMarketingActionJob::dispatch((string) $action->id, $actor?->id)->afterCommit();
                $queued[] = (string) $action->id;
            }

            $gated[] = $this->checkpoint($action, (string) ($gate['decision'] ?? 'requires_approval'), (string) ($gate['reason'] ?? 'Review required.'), $gate);
        }

        return [
            'planning' => $planned,
            'gated_actions' => $gated,
            'queued_action_ids' => $queued,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function generateCampaignProposal(Workspace $workspace, AgenticMarketingWorkflowRule $rule, array $input, ?User $actor): array
    {
        $topic = $input['topic'] ?? $this->campaignTopic($workspace);
        if (! $topic) {
            return ['created' => false, 'campaign_id' => null, 'reason' => 'No campaign-worthy topic or recommendation was available.'];
        }

        $campaign = $this->campaignPlanner->plan($workspace, (string) $topic, [
            'goals' => ['Respond to signal-driven opportunity', 'Prepare approval-gated content and distribution plan'],
            'audience' => $input['audience'] ?? '',
            'owner_user_id' => $actor?->id,
            'start_date' => now()->addWeek()->toDateString(),
        ]);

        return [
            'created' => true,
            'campaign_id' => (string) $campaign->id,
            'name' => $campaign->name,
            'approval_status' => $campaign->approval_status?->value ?? $campaign->approval_status,
            'scheduled_distribution_is_draft_only' => (bool) $rule->schedule_distribution_drafts,
            'reason' => 'Campaign proposal generated from signal-driven topic and stored as approval-gated draft assets.',
        ];
    }

    private function campaignTopic(Workspace $workspace): ?string
    {
        $recommendation = LearningRecommendation::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('type', [
                LearningRecommendationType::CAMPAIGN_EXPANSION->value,
                LearningRecommendationType::SUPPORTING_CONTENT->value,
            ])
            ->where('status', 'proposed')
            ->orderByDesc('priority_score')
            ->first();

        if ($recommendation) {
            return $recommendation->campaign?->name
                ?: $recommendation->content?->primary_keyword
                ?: $recommendation->title;
        }

        $opportunity = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'open')
            ->orderByDesc('priority_score')
            ->first();

        return $opportunity?->topic ?: $opportunity?->title;
    }

    private function ruleFor(Workspace $workspace, string $triggerType, array $input): AgenticMarketingWorkflowRule
    {
        if (! empty($input['rule_id'])) {
            $rule = AgenticMarketingWorkflowRule::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($input['rule_id'])
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return AgenticMarketingWorkflowRule::query()
            ->where('workspace_id', $workspace->id)
            ->where('trigger_type', $triggerType)
            ->where('status', AgenticMarketingWorkflowRule::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first()
            ?: AgenticMarketingWorkflowRule::defaultFor($workspace, $triggerType);
    }

    private function activeOverrides(Workspace $workspace): Collection
    {
        return AgenticMarketingWorkflowOverride::query()
            ->where('workspace_id', $workspace->id)
            ->active()
            ->get();
    }

    private function isPaused(Collection $overrides): bool
    {
        return $overrides->contains(fn (AgenticMarketingWorkflowOverride $override): bool => $override->override_type === AgenticMarketingWorkflowOverride::TYPE_PAUSE_WORKFLOW);
    }

    private function forceApproval(Collection $overrides): bool
    {
        return $overrides->contains(fn (AgenticMarketingWorkflowOverride $override): bool => $override->override_type === AgenticMarketingWorkflowOverride::TYPE_FORCE_APPROVAL);
    }

    private function actionBlocked(AgenticMarketingAction $action, Collection $overrides, AgenticMarketingWorkflowRule $rule): bool
    {
        $allowed = (array) ($rule->allowed_action_types ?? []);
        if ($allowed !== [] && ! in_array((string) $action->action_type, $allowed, true)) {
            return true;
        }

        return $overrides->contains(function (AgenticMarketingWorkflowOverride $override) use ($action): bool {
            if ($override->override_type !== AgenticMarketingWorkflowOverride::TYPE_BLOCK_ACTION) {
                return false;
            }

            $blockedType = data_get($override->payload, 'action_type');

            return $blockedType === null || $blockedType === (string) $action->action_type;
        });
    }

    private function actionConfidence(AgenticMarketingAction $action): int
    {
        return max(0, min(100, (int) (
            data_get($action->payload, 'planning.confidence_score')
            ?? data_get($action->payload, 'recommendation.confidence_score')
            ?? $action->opportunity?->priority_score
            ?? 50
        )));
    }

    private function isPublicationLike(AgenticMarketingAction $action): bool
    {
        return in_array((string) $action->action_type, ['publish_content', 'republish_content'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function actionExplanation(AgenticMarketingAction $action): array
    {
        return [
            'action_type' => (string) $action->action_type,
            'opportunity_id' => $action->opportunity_id ? (string) $action->opportunity_id : null,
            'opportunity_title' => $action->opportunity?->title,
            'priority_score' => $action->opportunity?->priority_score,
            'source_payload' => data_get($action->payload, 'recommendation'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkpoint(AgenticMarketingAction $action, string $decision, string $reason, ?array $gate): array
    {
        return [
            'subject_type' => AgenticMarketingAction::class,
            'subject_id' => (string) $action->id,
            'action_type' => (string) $action->action_type,
            'decision' => $decision,
            'requires_human_approval' => $decision !== AgenticApprovalGate::DECISION_ALLOWED,
            'reason' => $reason,
            'confidence_score' => $this->actionConfidence($action),
            'gate' => $gate,
        ];
    }

    /**
     * @param  array<string,mixed>  $actionSummary
     * @param  array<string,mixed>  $campaignSummary
     * @return list<array<string,mixed>>
     */
    private function approvalCheckpoints(array $actionSummary, array $campaignSummary, AgenticMarketingWorkflowRule $rule, Collection $overrides): array
    {
        $checkpoints = (array) ($actionSummary['gated_actions'] ?? []);

        if ((bool) ($campaignSummary['created'] ?? false)) {
            $checkpoints[] = [
                'subject_type' => Campaign::class,
                'subject_id' => $campaignSummary['campaign_id'],
                'decision' => AgenticApprovalGate::DECISION_REQUIRES_APPROVAL,
                'requires_human_approval' => true,
                'reason' => 'Campaign proposals are draft-only and require campaign approval before execution.',
                'confidence_score' => (int) $rule->minimum_confidence_score,
            ];
        }

        if ($this->forceApproval($overrides)) {
            $checkpoints[] = [
                'subject_type' => 'workflow',
                'subject_id' => null,
                'decision' => AgenticApprovalGate::DECISION_REQUIRES_APPROVAL,
                'requires_human_approval' => true,
                'reason' => 'Active human override forces approval for all workflow outcomes.',
                'confidence_score' => null,
            ];
        }

        return $checkpoints;
    }

    private function summary(array $output): string
    {
        $actions = count((array) data_get($output, 'actions.gated_actions', []));
        $queued = count((array) data_get($output, 'actions.queued_action_ids', []));
        $campaign = data_get($output, 'campaign_proposal.created') ? '1 campaign proposal' : 'no campaign proposal';

        return "Workflow completed with {$actions} approval checkpoint(s), {$queued} queued action(s), and {$campaign}.";
    }

    private function policySnapshot(Workspace $workspace, AgenticMarketingWorkflowRule $rule, Collection $overrides): array
    {
        return [
            'workspace_id' => (string) $workspace->id,
            'rule_id' => $rule->exists ? (string) $rule->id : null,
            'minimum_confidence_score' => (int) $rule->minimum_confidence_score,
            'maximum_actions_per_run' => (int) $rule->maximum_actions_per_run,
            'auto_queue_approved_actions' => (bool) $rule->auto_queue_approved_actions,
            'requires_human_approval' => (bool) $rule->requires_human_approval,
            'never_auto_publish_by_default' => true,
            'active_overrides' => $overrides->map(fn (AgenticMarketingWorkflowOverride $override): array => [
                'id' => (string) $override->id,
                'type' => $override->override_type,
                'reason' => $override->reason,
                'expires_at' => $override->expires_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function audit(AgentWorkflowRun $run, Workspace $workspace, string $event, ?array $before = null, ?array $after = null): void
    {
        AgenticMarketingAuditLog::query()->create([
            'organization_id' => $workspace->organization_id,
            'run_id' => null,
            'event' => $event,
            'subject_type' => AgentWorkflowRun::class,
            'subject_id' => (string) $run->id,
            'before' => $before,
            'after' => $after,
            'metadata' => [
                'workflow_key' => $run->workflow_key,
                'workspace_id' => (string) $workspace->id,
                'trigger_type' => $run->trigger_type,
            ],
        ]);
    }
}
