<?php

namespace App\Http\Controllers\Admin;

use App\Agents\Support\AgentRunStatus;
use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminAgentRunController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAgentRuns');

        $filters = [
            'agent_key' => trim((string) $request->query('agent_key', '')),
            'status' => trim((string) $request->query('status', '')),
            'trigger_type' => trim((string) $request->query('trigger_type', '')),
            'workspace_id' => trim((string) $request->query('workspace_id', '')),
            'site_id' => trim((string) $request->query('site_id', '')),
        ];

        $baseQuery = AgentRun::query()
            ->with([
                'organization:id,name',
                'workspace:id,name,display_name',
                'site:id,name',
                'content:id,title',
                'draft:id,title',
                'user:id,name',
            ])
            ->when($filters['agent_key'] !== '', fn (Builder $query) => $query->where('agent_key', $filters['agent_key']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['trigger_type'] !== '', fn (Builder $query) => $query->where('trigger_type', $filters['trigger_type']))
            ->when($filters['workspace_id'] !== '', fn (Builder $query) => $query->where('workspace_id', $filters['workspace_id']))
            ->when($filters['site_id'] !== '', fn (Builder $query) => $query->where('site_id', $filters['site_id']));

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'success' => (clone $baseQuery)->where('status', AgentRunStatus::SUCCESS->value)->count(),
            'skipped' => (clone $baseQuery)->where('status', AgentRunStatus::SKIPPED->value)->count(),
            'warning' => (clone $baseQuery)->where('status', AgentRunStatus::WARNING->value)->count(),
            'failed' => (clone $baseQuery)->where('status', AgentRunStatus::FAILED->value)->count(),
        ];

        $rows = (clone $baseQuery)
            ->latest('started_at')
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        $agentKeys = AgentRun::query()->select('agent_key')->distinct()->orderBy('agent_key')->pluck('agent_key')->all();
        $triggerTypes = AgentRun::query()->select('trigger_type')->distinct()->orderBy('trigger_type')->pluck('trigger_type')->all();

        return view('admin.agent-runs.index', [
            'filters' => $filters,
            'stats' => $stats,
            'rows' => $rows,
            'agentKeys' => $agentKeys,
            'triggerTypes' => $triggerTypes,
            'statuses' => [
                AgentRunStatus::SUCCESS->value,
                AgentRunStatus::SKIPPED->value,
                AgentRunStatus::WARNING->value,
                AgentRunStatus::FAILED->value,
            ],
        ]);
    }
}
