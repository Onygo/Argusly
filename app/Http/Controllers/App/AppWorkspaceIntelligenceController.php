<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\RunOrganizationEnrichmentJob;
use App\Jobs\RunPersonaEnrichmentJob;
use App\Jobs\RunTeamMemberEnrichmentJob;
use App\Models\EnrichmentRun;
use App\Models\OrganizationProfile;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Services\WorkspaceIntelligence\WorkspaceIntelligenceService;
use App\View\Presenters\WorkspaceIntelligencePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

class AppWorkspaceIntelligenceController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->user()->organization?->load([
            'organizationProfile',
            'personas' => fn ($query) => $query->orderBy('type')->orderBy('name'),
            'teamMembers' => fn ($query) => $query->orderBy('name'),
            'workspaces.companyProfile',
            'workspaces.defaultBrandVoice',
            'workspaces.brandContexts',
            'workspaces.defaultCompanyIntelligenceProfile',
            'workspaces.companyIntelligenceProfiles',
        ]);

        abort_unless($organization, 403);

        $runs = EnrichmentRun::query()
            ->where('organization_id', $organization->id)
            ->latest()
            ->limit(12)
            ->get();
        $activeTab = $this->resolveActiveTab($request);
        $hub = WorkspaceIntelligencePresenter::make(
            $organization,
            $organization->organizationProfile,
            $organization->personas,
            $organization->teamMembers,
            $runs,
            $organization->workspaces,
        );

        return view('app.workspace-intelligence.index', [
            'organization' => $organization,
            'organizationProfile' => $organization->organizationProfile,
            'personas' => $organization->personas,
            'teamMembers' => $organization->teamMembers,
            'runs' => $runs,
            'hub' => $hub,
            'activeTab' => $activeTab,
        ]);
    }

    public function showRun(Request $request, EnrichmentRun $run): JsonResponse
    {
        $this->ensureOwnership($request, $run);

        return response()->json([
            'id' => (string) $run->id,
            'status' => (string) $run->status,
            'progress' => (float) $run->progress,
            'enrichment_type' => (string) $run->enrichment_type,
            'source_type' => (string) $run->source_type,
            'error_message' => (string) ($run->error_message ?? ''),
            'ai_payload' => $run->ai_payload,
            'approved_at' => $run->approved_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ]);
    }

    public function storeOrganization(Request $request, WorkspaceIntelligenceService $workspaceIntelligence): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $data = $request->validate([
            'source_type' => ['required', 'in:website_url,company_name_and_industry,manual_text,linkedin_reference_url'],
            'website_url' => ['nullable', 'url', 'max:2048'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'manual_text' => ['nullable', 'string', 'max:12000'],
            'linkedin_reference_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $run = $workspaceIntelligence->createOrganizationProposalRun($organization, $data, $request->user());
        RunOrganizationEnrichmentJob::dispatch($run->id)->onQueue('default');

        return back()->with('status', 'Organization enrichment started. Review the proposal in Workspace Intelligence.');
    }

    public function storePersona(Request $request, WorkspaceIntelligenceService $workspaceIntelligence): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $data = $request->validate([
            'source_type' => ['required', 'in:website_url,company_name_and_industry,manual_text'],
            'website_url' => ['nullable', 'url', 'max:2048'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'manual_text' => ['nullable', 'string', 'max:12000'],
        ]);

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $run = $workspaceIntelligence->createBuyerPersonaRun($organization, $data, $request->user());
        RunPersonaEnrichmentJob::dispatch($run->id)->onQueue('default');

        return back()->with('status', 'Buyer persona enrichment started. Review the proposal in Workspace Intelligence.');
    }

    public function storeTeamMember(Request $request, WorkspaceIntelligenceService $workspaceIntelligence): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $data = $request->validate([
            'team_member_id' => ['required', 'integer'],
            'source_type' => ['required', 'in:manual_text,linkedin_reference_url,pasted_profile_text,uploaded_bio_text'],
            'manual_text' => ['nullable', 'string', 'max:12000'],
            'pasted_profile_text' => ['nullable', 'string', 'max:12000'],
            'uploaded_bio_text' => ['nullable', 'string', 'max:12000'],
            'linkedin_reference_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $teamMember = TeamMember::query()
            ->where('organization_id', $organization->id)
            ->whereKey($data['team_member_id'])
            ->firstOrFail();

        $run = $workspaceIntelligence->createTeamMemberPersonaRun($organization, $teamMember, $data, $request->user());
        RunTeamMemberEnrichmentJob::dispatch($run->id)->onQueue('default');

        return back()->with('status', 'Team member persona enrichment started. Review the proposal in Workspace Intelligence.');
    }

    public function approve(Request $request, EnrichmentRun $run, WorkspaceIntelligenceService $workspaceIntelligence): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureOwnership($request, $run);

        $data = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', 'max:120'],
            'persona_indexes' => ['nullable', 'array'],
            'persona_indexes.*' => ['integer', 'min:0'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $workspaceIntelligence->approveRun($run, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['workspace_intelligence' => $e->getMessage()]);
        }

        return back()->with('status', 'Proposal approved.')->with('workspace_intelligence_result', $result);
    }

    public function reject(Request $request, EnrichmentRun $run, WorkspaceIntelligenceService $workspaceIntelligence): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureOwnership($request, $run);

        $workspaceIntelligence->rejectRun($run);

        return back()->with('status', 'Proposal rejected.');
    }

    private function ensureOwnership(Request $request, EnrichmentRun $run): void
    {
        abort_unless((int) $run->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function resolveActiveTab(Request $request): string
    {
        $tab = trim((string) $request->query('tab', 'brand-profile'));

        return in_array($tab, ['brand-profile', 'personas', 'team', 'insights'], true)
            ? $tab
            : 'brand-profile';
    }
}
