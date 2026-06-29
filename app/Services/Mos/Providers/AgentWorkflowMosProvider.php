<?php

namespace App\Services\Mos\Providers;

use App\Agents\Contracts\AgentWorkflowInterface;
use App\Agents\Workflows\DraftPostProcessingWorkflow;
use App\Agents\Workflows\PublishedContentOptimizationWorkflow;
use App\Models\AgentWorkflowRun;
use App\Services\Mos\Contracts\MosProvider;
use App\Services\Mos\MosDomain;

class AgentWorkflowMosProvider implements MosProvider
{
    public function key(): string
    {
        return 'agent-workflows';
    }

    public function domain(): string
    {
        return MosDomain::WORKFLOW;
    }

    public function label(): string
    {
        return 'Agent Workflows';
    }

    public function capabilities(): array
    {
        return [
            'trigger_workflows',
            'condition_steps',
            'record_workflow_runs',
            'coordinate_agents',
        ];
    }

    public function priority(): int
    {
        return 90;
    }

    public function metadata(): array
    {
        return [
            'canonical_run_model' => AgentWorkflowRun::class,
            'workflow_contract' => AgentWorkflowInterface::class,
            'workflow_classes' => [
                DraftPostProcessingWorkflow::class,
                PublishedContentOptimizationWorkflow::class,
            ],
            'backwards_compatible' => true,
        ];
    }
}
