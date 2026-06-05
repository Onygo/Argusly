<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgenticActionRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminAgenticActionRunController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAgentRuns');

        $filters = [
            'status' => trim((string) $request->query('status', '')),
            'action_type' => trim((string) $request->query('action_type', '')),
            'execution_mode' => trim((string) $request->query('execution_mode', '')),
            'workspace_id' => trim((string) $request->query('workspace_id', '')),
        ];

        $baseQuery = AgenticActionRun::query()
            ->with(['workspace:id,name,display_name,organization_id', 'workspace.organization:id,name', 'goal:id,name', 'action:id'])
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['action_type'] !== '', fn (Builder $query) => $query->where('action_type', $filters['action_type']))
            ->when($filters['execution_mode'] !== '', fn (Builder $query) => $query->where('execution_mode_snapshot', $filters['execution_mode']))
            ->when($filters['workspace_id'] !== '', fn (Builder $query) => $query->where('workspace_id', $filters['workspace_id']));

        $rows = (clone $baseQuery)
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.agentic-action-runs.index', [
            'filters' => $filters,
            'rows' => $rows,
            'statuses' => AgenticActionRun::statuses(),
            'actionTypes' => AgenticActionRun::query()->select('action_type')->distinct()->orderBy('action_type')->pluck('action_type')->all(),
        ]);
    }
}
