<?php

namespace App\Services\Onboarding\Providers;

use App\Models\Brief;
use App\Models\Draft;
use App\Models\OpportunityExecutionPlan;
use App\Models\Workspace;
use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessRequirement;

class ContentOperationsReadinessProvider extends BaseReadinessProvider
{
    public function key(): string { return 'content_operations'; }

    public function label(): string { return 'Content Operations'; }

    public function description(): string { return 'Guides plans through briefs, drafts, and draft governance.'; }

    public function evaluate(Workspace $workspace): ModuleReadinessResult
    {
        $plans = OpportunityExecutionPlan::query()->where('workspace_id', $workspace->id)->count();
        $briefs = Brief::query()->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))->where('source', 'opportunity_execution_plan')->count();
        $drafts = Draft::query()->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))->whereNotNull('meta->source_context->execution_plan_id')->count();
        $approvedDrafts = Draft::query()->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))->where('status', Draft::STATUS_APPROVED_FOR_PUBLISHING)->count();

        $requirements = [
            new ReadinessRequirement('execution_plan', 'Create an execution plan', 'Content operations starts from execution planning.', $plans >= 1, 'required', 'Open Opportunity Intelligence', $this->routeOrNull('app.agentic-marketing.intelligence.index')),
            new ReadinessRequirement('brief', 'Create a content brief', 'Convert an execution plan into a brief.', $briefs >= 1, 'required', 'Open Setup', $this->routeOrNull('app.setup.index')),
            new ReadinessRequirement('draft', 'Create a first draft', 'Convert the brief into a first draft.', $drafts >= 1, 'required', 'Open content', $this->routeOrNull('app.content.index')),
            new ReadinessRequirement('approved_draft', 'Approve draft governance', 'Approve a draft for publishing without triggering publication.', $approvedDrafts >= 1, 'recommended', 'Open drafts', $this->routeOrNull('app.drafts')),
        ];

        return $this->result($requirements, [
            $this->action('Open Setup', 'Review content operations setup.', $this->routeOrNull('app.setup.index'), 'primary'),
            $this->action('Open Content', 'Review briefs and drafts.', $this->routeOrNull('app.content.index')),
        ], 'Content Operations starts after an Execution Plan is converted into a Brief.', $approvedDrafts > 0);
    }
}
