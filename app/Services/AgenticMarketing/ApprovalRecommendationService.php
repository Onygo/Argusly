<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticActionRun;
use Illuminate\Support\Collection;

class ApprovalRecommendationService
{
    /**
     * @param Collection<int,AgenticActionRun> $runs
     * @return array<string,mixed>
     */
    public function summarize(Collection $runs): array
    {
        $lowRisk = $runs->filter(fn (AgenticActionRun $run): bool => $this->isRecommendedApproval($run))->values();
        $needsJudgment = $runs
            ->filter(fn (AgenticActionRun $run): bool => $run->status === AgenticActionRun::STATUS_APPROVAL_REQUIRED && ! $this->isRecommendedApproval($run))
            ->values();
        $blocked = $runs
            ->filter(fn (AgenticActionRun $run): bool => $run->status === AgenticActionRun::STATUS_BLOCKED || $this->isBlockedByMissingDestination($run))
            ->values();

        return [
            'headline' => $this->headline($lowRisk, $needsJudgment, $blocked),
            'recommended_approval_runs' => $lowRisk,
            'judgment_runs' => $needsJudgment,
            'blocked_runs' => $blocked,
            'recommended_count' => $lowRisk->count(),
            'judgment_count' => $needsJudgment->count(),
            'blocked_count' => $blocked->count(),
            'total_count' => $runs->count(),
            'recommended_run_ids' => $lowRisk->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            'messages' => [
                'recommend' => $lowRisk->isNotEmpty()
                    ? sprintf('I recommend approving these %d low-risk action%s.', $lowRisk->count(), $lowRisk->count() === 1 ? '' : 's')
                    : 'I do not recommend bulk approval right now.',
                'judgment' => $needsJudgment->isNotEmpty()
                    ? sprintf('I need your judgment on these %d action%s.', $needsJudgment->count(), $needsJudgment->count() === 1 ? '' : 's')
                    : 'No higher-risk actions need individual judgment.',
                'blocked' => $blocked->isNotEmpty()
                    ? sprintf('%d action%s blocked before approval can happen.', $blocked->count(), $blocked->count() === 1 ? ' is' : 's are')
                    : 'No actions are blocked.',
            ],
        ];
    }

    public function isRecommendedApproval(AgenticActionRun $run): bool
    {
        if ($run->status !== AgenticActionRun::STATUS_APPROVAL_REQUIRED) {
            return false;
        }

        if ($this->isBlockedByMissingDestination($run)) {
            return false;
        }

        $risk = $this->riskLevel($run);

        return in_array($risk, ['low', ''], true)
            && (int) ($run->estimated_credits ?? 0) <= 10;
    }

    public function riskLevel(AgenticActionRun $run): string
    {
        return strtolower((string) data_get(
            $run->input_snapshot,
            'payload.planning.risk_level',
            data_get($run->policy_snapshot, 'risk_level', 'low')
        ));
    }

    public function approvalReason(AgenticActionRun $run): string
    {
        if ($this->isBlockedByMissingDestination($run)) {
            return 'This action is blocked because no destination site exists.';
        }

        if ($this->isRecommendedApproval($run)) {
            return 'Low risk, low credit impact, and ready for governed execution.';
        }

        $risk = $this->riskLevel($run);
        if (! in_array($risk, ['low', ''], true)) {
            return 'This needs judgment because the risk level is '.$risk.'.';
        }

        if ((int) ($run->estimated_credits ?? 0) > 10) {
            return 'This needs judgment because the estimated credit impact is higher than the low-risk threshold.';
        }

        return $run->reason ?: 'This action needs your review before Argusly can proceed.';
    }

    public function conversationPrompt(AgenticActionRun $run): string
    {
        if ($this->isBlockedByMissingDestination($run)) {
            return 'I need a destination site before I can safely run this.';
        }

        if ($this->isRecommendedApproval($run)) {
            return 'I recommend approving this action.';
        }

        return 'I need your judgment on this action.';
    }

    private function isBlockedByMissingDestination(AgenticActionRun $run): bool
    {
        $payload = (array) data_get($run->input_snapshot, 'payload', []);
        $destination = $run->goal?->clientSite
            ?: $run->action?->objective?->clientSite
            ?: data_get($payload, 'client_site_id');

        return ! $destination;
    }

    /**
     * @param Collection<int,AgenticActionRun> $recommended
     * @param Collection<int,AgenticActionRun> $judgment
     * @param Collection<int,AgenticActionRun> $blocked
     */
    private function headline(Collection $recommended, Collection $judgment, Collection $blocked): string
    {
        if ($recommended->isNotEmpty()) {
            return sprintf('Approve %d recommended action%s', $recommended->count(), $recommended->count() === 1 ? '' : 's');
        }

        if ($judgment->isNotEmpty()) {
            return sprintf('Review %d action%s that need judgment', $judgment->count(), $judgment->count() === 1 ? '' : 's');
        }

        if ($blocked->isNotEmpty()) {
            return sprintf('Resolve %d blocked action%s', $blocked->count(), $blocked->count() === 1 ? '' : 's');
        }

        return 'No approval decisions needed';
    }
}
