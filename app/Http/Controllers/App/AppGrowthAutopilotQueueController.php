<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GrowthAutopilotQueueItem;
use App\Services\GrowthAutopilot\GrowthAutopilotQueueBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppGrowthAutopilotQueueController extends Controller
{
    public function approve(Request $request, GrowthAutopilotQueueItem $item, GrowthAutopilotQueueBuilder $builder): RedirectResponse
    {
        $this->authorizeWorkspace($request, $item);
        $builder->approve($item);

        return back()->with('status', 'Growth autopilot action approved.');
    }

    public function dismiss(Request $request, GrowthAutopilotQueueItem $item, GrowthAutopilotQueueBuilder $builder): RedirectResponse
    {
        $this->authorizeWorkspace($request, $item);
        $builder->dismiss($item);

        return back()->with('status', 'Growth autopilot action dismissed.');
    }

    private function authorizeWorkspace(Request $request, GrowthAutopilotQueueItem $item): void
    {
        $workspaceIds = $request->user()?->organization?->workspaces()->pluck('workspaces.id')->all() ?? [];

        abort_unless(in_array($item->workspace_id, $workspaceIds, true), 404);
    }
}
