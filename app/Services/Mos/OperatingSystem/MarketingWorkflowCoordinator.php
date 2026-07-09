<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingWorkflow;

class MarketingWorkflowCoordinator
{
    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(MarketingObjective|MarketingInitiative $subject, array $attributes = []): MarketingWorkflow
    {
        $objective = $subject instanceof MarketingObjective ? $subject : $subject->objective;
        $initiative = $subject instanceof MarketingInitiative ? $subject : null;

        $workflow = MarketingWorkflow::query()->create(array_merge([
            'organization_id' => $subject->organization_id,
            'workspace_id' => $subject->workspace_id,
            'marketing_objective_id' => $objective?->id,
            'marketing_initiative_id' => $initiative?->id,
            'workflow_key' => 'marketing_operating_system',
            'name' => 'Marketing operating workflow',
            'status' => MarketingWorkflow::STATUS_DRAFT,
            'current_stage' => 'planning',
            'stages_json' => ['planning', 'approval', 'execution', 'measurement', 'review'],
            'gates_json' => [],
            'metadata_json' => [],
        ], $attributes));

        $this->timeline->record(
            $subject,
            'workflow.created',
            'Marketing workflow created',
            $workflow->name,
            metadata: [
                'workflow_key' => $workflow->workflow_key,
                'current_stage' => $workflow->current_stage,
                'status' => $workflow->status,
            ],
        );

        return $workflow;
    }

    public function advance(MarketingWorkflow $workflow, string $stage, string $status = MarketingWorkflow::STATUS_ACTIVE): MarketingWorkflow
    {
        $workflow->forceFill([
            'current_stage' => $stage,
            'status' => $status,
            'started_at' => $workflow->started_at ?: now(),
            'completed_at' => $status === MarketingWorkflow::STATUS_COMPLETED ? now() : $workflow->completed_at,
        ])->save();

        $subject = $workflow->initiative ?: $workflow->objective;

        if ($subject) {
            $this->timeline->record(
                $subject,
                'workflow.advanced',
                'Marketing workflow advanced',
                $stage,
                metadata: [
                    'workflow_id' => $workflow->id,
                    'workflow_key' => $workflow->workflow_key,
                    'status' => $workflow->status,
                ],
            );
        }

        return $workflow->refresh();
    }
}
