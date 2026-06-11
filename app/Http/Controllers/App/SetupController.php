<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Onboarding\FirstValueActivationService;
use App\Services\Onboarding\WorkspaceReadinessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function index(Request $request, WorkspaceReadinessService $readiness, FirstValueActivationService $activation): View
    {
        $workspace = $this->resolveWorkspace($request);

        return view('app.setup.index', array_merge($readiness->getWorkspaceReadiness($workspace), [
            'title' => 'Setup',
            'activation' => $activation->forWorkspace($workspace),
            'workspaces' => Workspace::query()
                ->where('organization_id', $request->user()->organization_id)
                ->orderBy('created_at')
                ->get(),
        ]));
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('created_at')
            ->firstOrFail();
    }
}
