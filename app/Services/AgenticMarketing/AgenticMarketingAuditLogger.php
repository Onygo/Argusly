<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingAuditLog;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AgenticMarketingAuditLogger
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed> $metadata
     */
    public function record(Model $subject, string $event, ?array $before = null, ?array $after = null, array $metadata = []): AgenticMarketingAuditLog
    {
        return AgenticMarketingAuditLog::query()->create(array_merge($this->context($subject), [
            'actor_id' => Auth::id(),
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function context(Model $subject): array
    {
        $objective = null;
        $opportunity = null;
        $action = null;
        $run = null;

        if ($subject instanceof AgenticMarketingObjective) {
            $objective = $subject;
        } elseif ($subject instanceof AgenticMarketingOpportunity) {
            $opportunity = $subject;
            $objective = $subject->objective;
        } elseif ($subject instanceof AgenticMarketingAction) {
            $action = $subject;
            $opportunity = $subject->opportunity;
            $objective = $subject->objective;
            $run = $subject->run;
        } elseif ($subject instanceof AgenticMarketingRun) {
            $run = $subject;
            $objective = $subject->objective;
        }

        return [
            'organization_id' => $objective?->organization_id,
            'objective_id' => $objective?->id,
            'opportunity_id' => $opportunity?->id,
            'action_id' => $action?->id,
            'run_id' => $run?->id,
        ];
    }
}
