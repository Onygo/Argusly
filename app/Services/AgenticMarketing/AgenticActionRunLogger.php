<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Arr;

class AgenticActionRunLogger
{
    public function recordStandalone(Workspace $workspace, string $actionType, string $status, array $attributes = []): AgenticActionRun
    {
        $run = new AgenticActionRun();
        $run->fill(array_filter(array_replace([
            'workspace_id' => $workspace->id,
            'brand_id' => data_get($attributes, 'brand_id'),
            'goal_id' => data_get($attributes, 'goal_id'),
            'opportunity_id' => data_get($attributes, 'opportunity_id'),
            'content_id' => data_get($attributes, 'content_id'),
            'action_type' => $actionType,
            'execution_mode_snapshot' => data_get($attributes, 'execution_mode_snapshot', 'guided'),
            'status' => $status,
            'reason' => data_get($attributes, 'reason'),
            'policy_snapshot' => (array) data_get($attributes, 'policy_snapshot', []),
            'input_snapshot' => (array) data_get($attributes, 'input_snapshot', []),
            'output_snapshot' => (array) data_get($attributes, 'output_snapshot', []),
            'estimated_credits' => data_get($attributes, 'estimated_credits'),
            'actual_credits' => data_get($attributes, 'actual_credits'),
            'approved_by' => data_get($attributes, 'approved_by'),
            'approved_at' => data_get($attributes, 'approved_at'),
            'executed_by_agent' => (bool) data_get($attributes, 'executed_by_agent', false),
            'job_id' => data_get($attributes, 'job_id'),
            'error_message' => data_get($attributes, 'error_message'),
        ], $attributes), fn (mixed $value): bool => $value !== null));
        $run->save();

        return $run;
    }

    public function recordActionSnapshot(AgenticMarketingAction $action, ?User $actor = null): ?AgenticActionRun
    {
        $status = match ((string) $action->status) {
            AgenticMarketingAction::STATUS_PROPOSED => AgenticActionRun::STATUS_PROPOSED,
            AgenticMarketingAction::STATUS_APPROVED => AgenticActionRun::STATUS_APPROVED,
            AgenticMarketingAction::STATUS_RUNNING => AgenticActionRun::STATUS_RUNNING,
            AgenticMarketingAction::STATUS_COMPLETED => AgenticActionRun::STATUS_COMPLETED,
            AgenticMarketingAction::STATUS_FAILED => AgenticActionRun::STATUS_FAILED,
            AgenticMarketingAction::STATUS_DISMISSED => AgenticActionRun::STATUS_CANCELLED,
            default => AgenticActionRun::STATUS_PROPOSED,
        };

        return $this->upsertForAction($action, [
            'status' => $status,
            'reason' => $action->error_message ?: data_get($action->payload, 'reason'),
            'input_snapshot' => $this->inputSnapshot($action),
            'output_snapshot' => (array) ($action->result ?? []),
            'estimated_credits' => $action->estimated_credits,
            'actual_credits' => $action->credits_captured,
            'approved_at' => $action->approved_at,
            'approved_by' => $status === AgenticActionRun::STATUS_APPROVED ? $actor?->id : null,
            'error_message' => $status === AgenticActionRun::STATUS_FAILED ? $action->error_message : null,
        ]);
    }

    public function recordGateDecision(AgenticMarketingAction $action, array $decision, ?User $actor = null, array $input = []): AgenticActionRun
    {
        $status = match (true) {
            (bool) ($decision['blocked'] ?? false) => AgenticActionRun::STATUS_BLOCKED,
            (bool) ($decision['requires_approval'] ?? false) => AgenticActionRun::STATUS_APPROVAL_REQUIRED,
            default => AgenticActionRun::STATUS_APPROVED,
        };

        return $this->upsertForAction($action, [
            'execution_mode_snapshot' => (string) data_get($decision, 'policy_snapshot.mode', 'guided'),
            'status' => $status,
            'reason' => (string) ($decision['reason'] ?? ''),
            'policy_snapshot' => (array) ($decision['policy_snapshot'] ?? []),
            'input_snapshot' => array_replace_recursive($this->inputSnapshot($action), $input),
            'estimated_credits' => $decision['estimated_credit_impact'] ?? $action->estimated_credits,
            'executed_by_agent' => $this->isAutonomousAgentRun($decision, $actor),
        ]);
    }

    public function markApproved(AgenticMarketingAction $action, User $approver): AgenticActionRun
    {
        return $this->upsertForAction($action, [
            'status' => AgenticActionRun::STATUS_APPROVED,
            'reason' => 'Customer approved the action.',
            'approved_by' => $approver->id,
            'approved_at' => $action->approved_at ?: now(),
        ]);
    }

    public function markQueued(AgenticMarketingAction $action, array $decision, ?User $actor = null, ?string $claimId = null): AgenticActionRun
    {
        return $this->upsertForAction($action, [
            'execution_mode_snapshot' => (string) data_get($decision, 'policy_snapshot.mode', 'guided'),
            'status' => AgenticActionRun::STATUS_QUEUED,
            'reason' => (string) ($decision['reason'] ?? 'Action queued for execution.'),
            'policy_snapshot' => (array) ($decision['policy_snapshot'] ?? []),
            'input_snapshot' => array_replace_recursive($this->inputSnapshot($action), ['claim_id' => $claimId]),
            'estimated_credits' => $decision['estimated_credit_impact'] ?? $action->estimated_credits,
            'executed_by_agent' => $this->isAutonomousAgentRun($decision, $actor),
            'job_id' => $claimId,
        ]);
    }

    public function markRunning(AgenticMarketingAction $action, ?string $jobId = null): AgenticActionRun
    {
        return $this->upsertForAction($action, [
            'status' => AgenticActionRun::STATUS_RUNNING,
            'job_id' => $jobId,
            'error_message' => null,
        ]);
    }

    public function markCompleted(AgenticMarketingAction $action): AgenticActionRun
    {
        $run = $this->upsertForAction($action, [
            'status' => AgenticActionRun::STATUS_COMPLETED,
            'reason' => data_get($action->result, 'summary'),
            'output_snapshot' => (array) ($action->result ?? []),
            'content_id' => $action->content_id,
            'actual_credits' => $action->credits_captured,
            'error_message' => null,
        ]);

        app(AgenticLearningSignalService::class)->recordForAction($action, $run);

        return $run->fresh() ?? $run;
    }

    public function markFailed(AgenticMarketingAction $action, string $message): AgenticActionRun
    {
        $run = $this->upsertForAction($action, [
            'status' => AgenticActionRun::STATUS_FAILED,
            'reason' => $message,
            'output_snapshot' => (array) ($action->result ?? []),
            'actual_credits' => $action->credits_captured,
            'error_message' => $message,
        ]);

        app(AgenticLearningSignalService::class)->recordForAction($action, $run);

        return $run->fresh() ?? $run;
    }

    private function upsertForAction(AgenticMarketingAction $action, array $attributes): AgenticActionRun
    {
        $action->loadMissing(['objective', 'opportunity']);
        $workspaceId = (string) ($action->objective?->workspace_id ?: data_get($action->payload, 'workspace_id'));

        if ($workspaceId === '') {
            return new AgenticActionRun();
        }

        $base = [
            'workspace_id' => $workspaceId,
            'brand_id' => $this->brandId($action),
            'goal_id' => $action->objective_id,
            'opportunity_id' => $action->opportunity_id,
            'content_id' => $action->content_id ?: data_get($action->payload, 'content_id'),
            'action_id' => $action->id,
            'action_type' => (string) $action->action_type,
            'execution_mode_snapshot' => 'guided',
            'policy_snapshot' => [],
            'input_snapshot' => $this->inputSnapshot($action),
            'output_snapshot' => (array) ($action->result ?? []),
            'estimated_credits' => $action->estimated_credits,
            'actual_credits' => $action->credits_captured,
        ];

        $run = AgenticActionRun::query()
            ->where('action_id', $action->id)
            ->latest()
            ->first();

        if (! $run) {
            $run = new AgenticActionRun();
        }

        if ($run->exists && $run->status === AgenticActionRun::STATUS_REJECTED && ($attributes['status'] ?? null) === AgenticActionRun::STATUS_CANCELLED) {
            unset($attributes['status']);
        }

        $run->fill(array_filter(array_replace($base, $attributes), fn (mixed $value): bool => $value !== null));
        $run->save();

        return $run;
    }

    private function inputSnapshot(AgenticMarketingAction $action): array
    {
        return [
            'action_id' => (string) $action->id,
            'objective_id' => (string) $action->objective_id,
            'opportunity_id' => $action->opportunity_id ? (string) $action->opportunity_id : null,
            'content_id' => $action->content_id ? (string) $action->content_id : data_get($action->payload, 'content_id'),
            'payload' => Arr::except((array) ($action->payload ?? []), ['api_key', 'token', 'password']),
        ];
    }

    private function brandId(AgenticMarketingAction $action): ?string
    {
        $brandId = data_get($action->payload, 'brand_id')
            ?: data_get($action->payload, 'brand_voice_id')
            ?: data_get($action->objective?->payload, 'brand_id')
            ?: data_get($action->objective?->payload, 'brand_voice_id');

        return is_scalar($brandId) && trim((string) $brandId) !== ''
            ? trim((string) $brandId)
            : null;
    }

    private function isAutonomousAgentRun(array $decision, ?User $actor): bool
    {
        return data_get($decision, 'policy_snapshot.mode') === 'autonomous'
            && ($actor === null || ! (bool) data_get($decision, 'policy_snapshot.evaluation.has_customer_approval', false));
    }
}
