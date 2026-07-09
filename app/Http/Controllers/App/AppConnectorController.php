<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Workspace;
use App\Services\DataConnectors\DataConnectorRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppConnectorController extends Controller
{
    public function index(Request $request, DataConnectorRegistry $registry): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);

        $this->authorize('viewAny', ConnectorAccount::class);

        $providers = ConnectorProvider::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->keyBy('provider_key');

        $accounts = ConnectorAccount::query()
            ->with(['provider', 'datasets'])
            ->forWorkspace($workspace)
            ->orderBy('provider_key')
            ->orderBy('account_name')
            ->get();

        return view('app.connectors.index', [
            'workspace' => $workspace,
            'providerDefinitions' => collect($registry->all()),
            'providers' => $providers,
            'accounts' => $accounts,
        ]);
    }

    public function show(Request $request, ConnectorAccount $connectorAccount): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 404);
        abort_unless((string) $connectorAccount->workspace_id === (string) $workspace->id, 404);

        $this->authorize('view', $connectorAccount);

        $connectorAccount->load([
            'provider',
            'clientSite',
            'datasets' => fn ($query) => $query->orderBy('display_name'),
            'syncRuns' => fn ($query) => $query->latest('created_at')->limit(25),
            'healthEvents' => fn ($query) => $query->latest('occurred_at')->limit(10),
        ]);

        return view('app.connectors.show', [
            'workspace' => $workspace,
            'account' => $connectorAccount,
        ]);
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            return Workspace::query()
                ->whereKey($impersonatedWorkspaceId)
                ->where('organization_id', $request->user()->organization_id)
                ->first();
        }

        $workspaceId = (string) $request->query('workspace_id', '');
        if ($workspaceId !== '') {
            return Workspace::query()
                ->whereKey($workspaceId)
                ->where('organization_id', $request->user()->organization_id)
                ->first();
        }

        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('created_at')
            ->first();
    }
}
