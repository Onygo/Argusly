<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GrowthAutopilotQueueItem;
use App\Services\ContentPackages\ContentPackageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppContentPackageController extends Controller
{
    public function storeFromQueueItem(
        Request $request,
        GrowthAutopilotQueueItem $item,
        ContentPackageService $packages,
    ): RedirectResponse {
        $this->authorizeWorkspace($request, $item);

        $package = $packages->prepareFromQueueItem($item, $request->user());

        return redirect()
            ->route('app.drafts.show', $package->draft_id)
            ->with('status', 'Content package prepared.');
    }

    private function authorizeWorkspace(Request $request, GrowthAutopilotQueueItem $item): void
    {
        $workspaceIds = $request->user()?->organization?->workspaces()->pluck('workspaces.id')->all() ?? [];

        abort_unless(in_array($item->workspace_id, $workspaceIds, true), 404);
    }
}
