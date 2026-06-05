<?php

namespace App\Services\AgenticMarketing;

class AgenticMarketingAutonomyDecision
{
    public const MODE_PROPOSE_ONLY = 'propose_only';
    public const MODE_AUTO_CREATE_DRAFT = 'auto_create_draft';
    public const MODE_GENERATE_PROPOSAL = 'generate_proposal';
    public const MODE_AUTO_APPLY = 'auto_apply';
    public const MODE_DRY_RUN = 'dry_run';

    public function __construct(
        public readonly string $mode,
        public readonly bool $approvalRequired,
        public readonly bool $mayAutoApply,
        public readonly bool $mayCreateDraft,
        public readonly bool $requiresApprovalToApply,
        public readonly bool $dryRun,
        public readonly string $reason,
        public readonly array $rules = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'approval_required' => $this->approvalRequired,
            'may_auto_apply' => $this->mayAutoApply,
            'may_create_draft' => $this->mayCreateDraft,
            'requires_approval_to_apply' => $this->requiresApprovalToApply,
            'dry_run' => $this->dryRun,
            'reason' => $this->reason,
            'rules' => $this->rules,
        ];
    }
}
