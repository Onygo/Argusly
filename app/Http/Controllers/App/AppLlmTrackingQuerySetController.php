<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuerySet;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppLlmTrackingQuerySetController extends Controller
{
    public function store(Request $request, ClientSite $site, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'locale' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        LlmTrackingQuerySet::query()->create([
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'locale' => trim((string) ($data['locale'] ?? 'en')) ?: 'en',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('app.sites.llm-tracking.index', $site)->with('status', 'Query set created.');
    }

    public function update(Request $request, ClientSite $site, LlmTrackingQuerySet $querySet, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQuerySetInSite($site, $querySet);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'locale' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $querySet->update([
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'locale' => trim((string) ($data['locale'] ?? 'en')) ?: 'en',
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('status', 'Query set updated.');
    }

    public function toggle(Request $request, ClientSite $site, LlmTrackingQuerySet $querySet, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQuerySetInSite($site, $querySet);

        $querySet->is_active = ! $querySet->is_active;
        $querySet->save();

        return back()->with('status', 'Query set status updated.');
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        if ((int) $site->workspace?->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }

    private function assertFeature(FeatureGate $featureGate, ClientSite $site): void
    {
        try {
            $featureGate->assert($site->workspace, 'link_intelligence');
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }
    }

    private function assertQuerySetInSite(ClientSite $site, LlmTrackingQuerySet $querySet): void
    {
        if ((string) $querySet->client_site_id !== (string) $site->id) {
            abort(404);
        }
    }
}
