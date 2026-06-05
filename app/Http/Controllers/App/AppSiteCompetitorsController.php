<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\LlmAuthorityEntityCandidate;
use App\Models\LlmAuthorityLearning;
use App\Models\SiteCompetitor;
use App\Services\Entitlements\FeatureGate;
use App\Services\LlmTracking\LlmAuthorityCandidateService;
use App\Services\PlanQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppSiteCompetitorsController extends Controller
{
    public function index(
        Request $request,
        ClientSite $site,
        FeatureGate $featureGate,
        PlanQuotaService $planQuotaService,
        LlmAuthorityCandidateService $candidateService,
    ): View
    {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $competitors = SiteCompetitor::query()
            ->where('client_site_id', $site->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('app.sites.competitors.index', [
            'site' => $site,
            'competitors' => $competitors,
            'authorityCandidates' => $candidateService->candidatesForSite((string) $site->id, ['candidate', 'accepted']),
            'authorityLearnings' => LlmAuthorityLearning::query()
                ->where('client_site_id', $site->id)
                ->where('status', 'active')
                ->orderBy('priority')
                ->latest()
                ->limit(12)
                ->get(),
            'competitorLimit' => $planQuotaService->limitForFeature($site->workspace, 'competitor_slots_limit', -1),
            'competitorUsed' => (int) $competitors->where('is_active', true)->count(),
            'competitorContextEnabled' => (bool) data_get($site->capabilities, 'competitor_context_enabled', false),
        ]);
    }

    public function store(Request $request, ClientSite $site, FeatureGate $featureGate, PlanQuotaService $planQuotaService): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $planQuotaService->assertCanAddCompetitor($site->workspace, $site);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['competitors' => $exception->getMessage()]);
        }

        SiteCompetitor::query()->create([
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'name' => (string) $data['name'],
            'domain' => (string) $data['domain'],
            'notes' => (string) ($data['notes'] ?? ''),
            'is_active' => true,
        ]);

        return back()->with('status', 'Competitor added.');
    }

    public function toggle(Request $request, ClientSite $site, SiteCompetitor $competitor, FeatureGate $featureGate, PlanQuotaService $planQuotaService): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertCompetitorInSite($site, $competitor);

        if (! $competitor->is_active) {
            try {
                $planQuotaService->assertCanAddCompetitor($site->workspace, $site);
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['competitors' => $exception->getMessage()]);
            }
        }

        $competitor->is_active = ! $competitor->is_active;
        $competitor->save();

        return back()->with('status', 'Competitor updated.');
    }

    public function updateContextSetting(Request $request, ClientSite $site, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $enabled = $request->boolean('competitor_context_enabled');

        $capabilities = is_array($site->capabilities) ? $site->capabilities : [];
        $capabilities['competitor_context_enabled'] = $enabled;

        $site->capabilities = $capabilities;
        $site->save();

        return back()->with('status', 'Competitor context setting updated.');
    }

    public function acceptCandidate(
        Request $request,
        ClientSite $site,
        LlmAuthorityEntityCandidate $candidate,
        FeatureGate $featureGate,
        LlmAuthorityCandidateService $candidateService,
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertCandidateInSite($site, $candidate);

        $candidateService->accept($candidate);

        return back()->with('status', 'Authority entity added as a tracked competitor/benchmark.');
    }

    public function ignoreCandidate(
        Request $request,
        ClientSite $site,
        LlmAuthorityEntityCandidate $candidate,
        FeatureGate $featureGate,
        LlmAuthorityCandidateService $candidateService,
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertCandidateInSite($site, $candidate);

        $candidateService->ignore($candidate);

        return back()->with('status', 'Authority entity candidate ignored.');
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

    private function assertCompetitorInSite(ClientSite $site, SiteCompetitor $competitor): void
    {
        if ((string) $competitor->client_site_id !== (string) $site->id) {
            abort(404);
        }
    }

    private function assertCandidateInSite(ClientSite $site, LlmAuthorityEntityCandidate $candidate): void
    {
        if ((string) $candidate->client_site_id !== (string) $site->id) {
            abort(404);
        }
    }
}
