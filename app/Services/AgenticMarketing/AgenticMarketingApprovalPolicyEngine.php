<?php

namespace App\Services\AgenticMarketing;

use App\Enums\AgenticMarketingActionType;
use App\Enums\AgenticMarketingApprovalMode;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;

class AgenticMarketingApprovalPolicyEngine
{
    public function decide(AgenticMarketingAction $action, bool $dryRun = false): AgenticMarketingAutonomyDecision
    {
        $action->loadMissing('objective');

        $actionType = AgenticMarketingActionType::tryFrom((string) $action->action_type);
        $approvalMode = AgenticMarketingApprovalMode::tryFrom((string) $action->objective?->approval_mode)
            ?: AgenticMarketingApprovalMode::Manual;
        $risk = $this->risk($action);
        $dryRun = $dryRun || (bool) data_get($action->payload ?? [], 'automation.dry_run', false);

        if ($dryRun) {
            return new AgenticMarketingAutonomyDecision(
                mode: AgenticMarketingAutonomyDecision::MODE_DRY_RUN,
                approvalRequired: true,
                mayAutoApply: false,
                mayCreateDraft: false,
                requiresApprovalToApply: true,
                dryRun: true,
                reason: 'Dry-run mode records the proposed outcome without creating drafts or applying changes.',
                rules: $this->rules($approvalMode, $risk, $actionType),
            );
        }

        if ($approvalMode !== AgenticMarketingApprovalMode::PolicyEngine) {
            return new AgenticMarketingAutonomyDecision(
                mode: $this->proposalMode($actionType),
                approvalRequired: true,
                mayAutoApply: false,
                mayCreateDraft: false,
                requiresApprovalToApply: true,
                dryRun: false,
                reason: $approvalMode === AgenticMarketingApprovalMode::ApprovalRequired
                    ? 'Objective requires approval before any generated recommendation can be applied.'
                    : 'Manual approval mode is proposal-only by default.',
                rules: $this->rules($approvalMode, $risk, $actionType),
            );
        }

        if ($risk === 'low' && $actionType === AgenticMarketingActionType::RefreshArticle) {
            return new AgenticMarketingAutonomyDecision(
                mode: AgenticMarketingAutonomyDecision::MODE_AUTO_CREATE_DRAFT,
                approvalRequired: false,
                mayAutoApply: false,
                mayCreateDraft: true,
                requiresApprovalToApply: true,
                dryRun: false,
                reason: 'Policy engine allows low-risk refreshes to create supervised drafts only.',
                rules: $this->rules($approvalMode, $risk, $actionType),
            );
        }

        if ($risk === 'low' && in_array($actionType, [
            AgenticMarketingActionType::UpdateMeta,
            AgenticMarketingActionType::AddSchema,
        ], true)) {
            return new AgenticMarketingAutonomyDecision(
                mode: AgenticMarketingAutonomyDecision::MODE_AUTO_APPLY,
                approvalRequired: false,
                mayAutoApply: true,
                mayCreateDraft: false,
                requiresApprovalToApply: false,
                dryRun: false,
                reason: 'Policy engine allows low-risk metadata/schema changes with rollback metadata.',
                rules: $this->rules($approvalMode, $risk, $actionType),
            );
        }

        return new AgenticMarketingAutonomyDecision(
            mode: $this->proposalMode($actionType),
            approvalRequired: $risk !== 'low',
            mayAutoApply: false,
            mayCreateDraft: false,
            requiresApprovalToApply: true,
            dryRun: false,
            reason: in_array($actionType, [
                AgenticMarketingActionType::AddAnswerBlock,
                AgenticMarketingActionType::ImproveInternalLinks,
            ], true)
                ? 'Policy engine can generate suggestions, but applying them still requires approval.'
                : 'Policy engine requires review for this action type or risk level.',
            rules: $this->rules($approvalMode, $risk, $actionType),
        );
    }

    public function planningPolicy(?string $approvalMode, AgenticMarketingActionType $actionType, string $risk): array
    {
        $mode = AgenticMarketingApprovalMode::tryFrom((string) $approvalMode) ?: AgenticMarketingApprovalMode::Manual;
        $decision = $this->decisionForPlanning($mode, $actionType, $risk);

        return [
            'required' => $decision->approvalRequired,
            'reason' => $decision->reason,
            'autonomy' => $decision->toArray(),
        ];
    }

    private function decisionForPlanning(AgenticMarketingApprovalMode $mode, AgenticMarketingActionType $actionType, string $risk): AgenticMarketingAutonomyDecision
    {
        $action = new AgenticMarketingAction([
            'action_type' => $actionType->value,
            'payload' => ['planning' => ['risk_level' => $risk]],
        ]);
        $action->setRelation('objective', new AgenticMarketingObjective(['approval_mode' => $mode->value]));

        return $this->decide($action);
    }

    private function proposalMode(?AgenticMarketingActionType $actionType): string
    {
        return in_array($actionType, [
            AgenticMarketingActionType::AddAnswerBlock,
            AgenticMarketingActionType::ImproveInternalLinks,
        ], true)
            ? AgenticMarketingAutonomyDecision::MODE_GENERATE_PROPOSAL
            : AgenticMarketingAutonomyDecision::MODE_PROPOSE_ONLY;
    }

    private function risk(AgenticMarketingAction $action): string
    {
        $risk = strtolower(trim((string) data_get($action->payload ?? [], 'planning.risk_level', 'medium')));

        return in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'medium';
    }

    private function rules(AgenticMarketingApprovalMode $mode, string $risk, ?AgenticMarketingActionType $actionType): array
    {
        return [
            'approval_mode' => $mode->value,
            'risk_level' => $risk,
            'action_type' => $actionType?->value,
            'never_auto_publish' => true,
            'auto_apply_requires_low_risk' => true,
            'auto_apply_requires_rollback_metadata' => true,
        ];
    }
}
