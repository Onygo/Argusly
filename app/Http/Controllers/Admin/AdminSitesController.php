<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Services\PluginUpdates\PluginReleaseService;
use Illuminate\View\View;

class AdminSitesController extends Controller
{
    public function index(PluginReleaseService $pluginReleaseService): View
    {
        $sites = ClientSite::query()
            ->with('workspace.organization')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $latestWpPluginVersion = $pluginReleaseService->latestRelease()?->version;

        return view('admin.sites.index', [
            'sites' => $sites,
            'latestWpPluginVersion' => $latestWpPluginVersion,
        ]);
    }
}
