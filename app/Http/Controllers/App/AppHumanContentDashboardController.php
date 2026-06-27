<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\HumanContent\HumanContentDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppHumanContentDashboardController extends Controller
{
    public function index(Request $request, HumanContentDashboardService $dashboard): View
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 404);

        $workspaces = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);

        $workspaceIds = $workspaces->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $filters = [
            'workspace_id' => trim((string) $request->query('workspace_id', '')),
            'site_id' => trim((string) $request->query('site_id', '')),
            'locale' => trim((string) $request->query('locale', '')),
            'content_type' => trim((string) $request->query('content_type', '')),
            'period' => (int) $request->query('period', 30),
        ];

        if ($filters['workspace_id'] !== '' && ! in_array($filters['workspace_id'], $workspaceIds, true)) {
            abort(404);
        }

        $sites = ClientSite::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->when($filters['workspace_id'] !== '', fn ($query) => $query->where('workspace_id', $filters['workspace_id']))
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name']);

        if ($filters['site_id'] !== '' && ! $sites->contains('id', $filters['site_id'])) {
            abort(404);
        }

        $payload = $dashboard->forOrganization($organization, $filters);

        return view('app.human-content.dashboard', [
            'dashboard' => $payload,
            'filters' => $payload['filters'],
            'workspaces' => $workspaces,
            'sites' => $sites,
            'locales' => ['en' => 'English', 'nl' => 'Dutch', 'de' => 'German'],
            'contentTypes' => ['article' => 'Article', 'blog' => 'Blog', 'page' => 'Page'],
            'periods' => [7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '365 days'],
        ]);
    }
}
