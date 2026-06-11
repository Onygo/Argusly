<?php

namespace App\Services\Onboarding\Providers;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\Workspace;
use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessRequirement;

class ExecutionPlanningReadinessProvider extends BaseReadinessProvider
{
    public function key(): string { return 'execution_planning'; }

    public function label(): string { return 'Execution Planning'; }

    public function description(): string { return 'Turns approved opportunities into deterministic execution plans.'; }

    public function evaluate(Workspace $workspace): ModuleReadinessResult
    {
        $approved = Opportunity::query()->where('workspace_id', $workspace->id)->where('status', OpportunityStatus::APPROVED->value)->count();
        $plans = OpportunityExecutionPlan::query()->where('workspace_id', $workspace->id)->count();

        $requirements = [
            new ReadinessRequirement('approved_opportunity', 'Approve an opportunity', 'Execution plans become available after opportunity approval.', $approved >= 1, 'required', 'Open Opportunity Intelligence', $this->routeOrNull('app.agentic-marketing.intelligence.index')),
            new ReadinessRequirement('execution_plan', 'Create an execution plan', 'An execution plan turns opportunity evidence into steps.', $plans >= 1, 'required', 'Open Opportunity Intelligence', $this->routeOrNull('app.agentic-marketing.intelligence.index')),
        ];

        return $this->result($requirements, [
            $this->action('Open Setup', 'See execution planning blockers.', $this->routeOrNull('app.setup.index'), 'primary'),
            $this->action('Open Opportunity Intelligence', 'Approve opportunities and create plans.', $this->routeOrNull('app.agentic-marketing.intelligence.index')),
        ], 'Execution Plans become available after an Opportunity is approved.', $plans > 0);
    }
}
