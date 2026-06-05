<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CrossLinkPermission;
use App\Models\LinkProfile;
use App\Models\Workspace;
use App\Services\PlanQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NetworkLinkingController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-cross-link-permissions');

        $organizationId = request()->user()->organization_id;

        $workspaces = Workspace::query()
            ->with('linkProfile')
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        $permissions = CrossLinkPermission::query()
            ->with(['fromWorkspace.organization', 'toWorkspace.organization', 'approvedByUser'])
            ->whereHas('fromWorkspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->orWhereHas('toWorkspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->latest()
            ->limit(100)
            ->get();

        $availableTargets = Workspace::query()
            ->with('organization')
            ->orderBy('name')
            ->limit(200)
            ->get();

        return view('app.network-linking.index', [
            'workspaces' => $workspaces,
            'permissions' => $permissions,
            'availableTargets' => $availableTargets,
        ]);
    }

    public function updateProfile(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manage-cross-link-permissions');
        $this->assertWorkspaceInUserOrganization($workspace);

        $data = $request->validate([
            'default_internal_linking_enabled' => ['nullable', 'boolean'],
            'external_suggestions_enabled' => ['nullable', 'boolean'],
            'max_outbound_links_per_article' => ['required', 'integer', 'min:1', 'max:30'],
            'max_cross_domain_links_per_month' => ['required', 'integer', 'min:1', 'max:300'],
            'min_similarity_threshold' => ['required', 'numeric', 'min:0.50', 'max:0.99'],
            'min_audience_overlap_threshold' => ['required', 'numeric', 'min:0.30', 'max:0.99'],
        ]);

        LinkProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'default_internal_linking_enabled' => (bool) ($data['default_internal_linking_enabled'] ?? false),
                'external_suggestions_enabled' => (bool) ($data['external_suggestions_enabled'] ?? false),
                'max_outbound_links_per_article' => (int) $data['max_outbound_links_per_article'],
                'max_cross_domain_links_per_month' => (int) $data['max_cross_domain_links_per_month'],
                'min_similarity_threshold' => (float) $data['min_similarity_threshold'],
                'min_audience_overlap_threshold' => (float) $data['min_audience_overlap_threshold'],
            ],
        );

        return back()->with('status', 'Link profile updated.');
    }

    public function requestPermission(Request $request, Workspace $workspace, PlanQuotaService $planQuotaService): RedirectResponse
    {
        Gate::authorize('manage-cross-link-permissions');
        $this->assertWorkspaceInUserOrganization($workspace);

        $data = $request->validate([
            'to_workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'relationship_type' => ['required', 'in:same_brand,partner,franchise,publisher_pool'],
        ]);

        try {
            $planQuotaService->assertCanAddCompetitor($workspace);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['network_linking' => $exception->getMessage()]);
        }

        CrossLinkPermission::query()->updateOrCreate(
            [
                'from_workspace_id' => $workspace->id,
                'to_workspace_id' => $data['to_workspace_id'],
            ],
            [
                'status' => 'pending',
                'relationship_type' => $data['relationship_type'],
                'approved_by_user_id' => null,
                'approved_at' => null,
            ],
        );

        return back()->with('status', 'Cross-domain permission requested.');
    }

    public function approvePermission(Request $request, CrossLinkPermission $permission): RedirectResponse
    {
        Gate::authorize('approve', $permission);

        $data = $request->validate([
            'rel_attribute' => ['nullable', 'in:follow,nofollow'],
        ]);

        $permission->update([
            'status' => 'approved',
            'rel_attribute' => (string) ($data['rel_attribute'] ?? 'follow'),
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('status', 'Cross-domain permission approved.');
    }

    public function revokePermission(Request $request, CrossLinkPermission $permission): RedirectResponse
    {
        Gate::authorize('approve', $permission);

        $permission->update([
            'status' => 'revoked',
            'approved_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Cross-domain permission revoked.');
    }

    private function assertWorkspaceInUserOrganization(Workspace $workspace): void
    {
        if ((int) $workspace->organization_id !== (int) request()->user()->organization_id) {
            abort(404);
        }
    }
}
