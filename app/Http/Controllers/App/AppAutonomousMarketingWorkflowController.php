<?php

namespace App\Http\Controllers\App;

use App\Agents\Support\AgentRunStatus;
use App\Http\Controllers\Controller;
use App\Jobs\AgenticMarketing\RunAutonomousMarketingWorkflowJob;
use App\Models\AgentWorkflowRun;
use App\Models\AgenticMarketingWorkflowOverride;
use App\Models\AgenticMarketingWorkflowRule;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AutonomousMarketingWorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppAutonomousMarketingWorkflowController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        $runs = AgentWorkflowRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('workflow_key', 'agentic_marketing_autonomous_orchestration')
            ->latest()
            ->limit(20)
            ->get();

        $rules = AgenticMarketingWorkflowRule::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->get();

        $overrides = AgenticMarketingWorkflowOverride::query()
            ->where('workspace_id', $workspace->id)
            ->active()
            ->latest()
            ->get();

        return view('app.agentic-marketing.workflows.index', [
            'workspace' => $workspace,
            'runs' => $runs,
            'rules' => $rules,
            'overrides' => $overrides,
            'latestRun' => $runs->first(),
            'triggerTypes' => ['signal_monitor', 'opportunity_detected', 'campaign_learning', 'content_decay', 'manual_review'],
        ]);
    }

    public function run(Request $request, AutonomousMarketingWorkflowEngine $engine): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $validated = $request->validate([
            'trigger_type' => ['required', 'string', 'max:80'],
            'topic' => ['nullable', 'string', 'max:180'],
            'run_inline' => ['nullable', 'boolean'],
        ]);

        $input = [
            'trigger_source' => 'ui',
            'topic' => $validated['topic'] ?? null,
        ];

        if ($request->boolean('run_inline')) {
            $run = $engine->run($workspace, $validated['trigger_type'], $input, $request->user());

            return redirect()
                ->route('app.agentic-marketing.workflows.index', ['workspace_id' => $workspace->id])
                ->with('status', 'Workflow completed: '.$run->summary);
        }

        RunAutonomousMarketingWorkflowJob::dispatch((string) $workspace->id, $validated['trigger_type'], $input, $request->user()->id)->afterCommit();

        return back()->with('status', 'Autonomous marketing workflow queued.');
    }

    public function storeOverride(Request $request): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $validated = $request->validate([
            'override_type' => ['required', 'in:pause_workflow,force_approval,block_action'],
            'reason' => ['required', 'string', 'max:1000'],
            'action_type' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date'],
        ]);

        AgenticMarketingWorkflowOverride::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'user_id' => $request->user()->id,
            'override_type' => $validated['override_type'],
            'reason' => $validated['reason'],
            'payload' => array_filter(['action_type' => $validated['action_type'] ?? null]),
            'is_active' => true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return back()->with('status', 'Human override saved.');
    }

    public function clearOverride(Request $request, AgenticMarketingWorkflowOverride $override): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless((string) $override->workspace_id === (string) $workspace->id, 404);

        $override->forceFill(['is_active' => false])->save();

        return back()->with('status', 'Human override cleared.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'trigger_type' => ['required', 'string', 'max:80'],
            'minimum_confidence_score' => ['required', 'integer', 'min:0', 'max:100'],
            'maximum_actions_per_run' => ['required', 'integer', 'min:1', 'max:50'],
            'auto_queue_approved_actions' => ['nullable', 'boolean'],
        ]);

        AgenticMarketingWorkflowRule::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'name' => $validated['name'],
            'trigger_type' => $validated['trigger_type'],
            'minimum_confidence_score' => $validated['minimum_confidence_score'],
            'maximum_actions_per_run' => $validated['maximum_actions_per_run'],
            'auto_queue_approved_actions' => $request->boolean('auto_queue_approved_actions'),
            'requires_human_approval' => true,
            'policy' => ['never_auto_publish_by_default' => true],
        ]);

        return back()->with('status', 'Workflow rule saved.');
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }
}
