<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\OnboardingState;
use App\Models\Workspace;
use App\Services\Onboarding\OnboardingStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppOnboardingController extends Controller
{
    public function show(Request $request, OnboardingStateService $states): View|RedirectResponse
    {
        $user = $request->user();
        $state = $states->ensureForUser($user);
        $completed = is_array($state->completed_steps_json) ? $state->completed_steps_json : [];
        $wizardCompleted = count(array_intersect(['intent', 'company_profile', 'connect_site'], $completed)) === 3;

        if ($state->phase === OnboardingState::PHASE_ACTIVATED || $wizardCompleted) {
            return redirect()->route('app.activation.index');
        }

        $workspace = $this->resolveOnboardingWorkspace($user, $state);

        $profile = $workspace?->companyProfile;
        $siteCount = $user->organization
            ? $user->organization->clientSites()->where('is_active', true)->count()
            : 0;

        $steps = [
            'intent' => in_array('intent', $completed, true),
            'company_profile' => in_array('company_profile', $completed, true),
            'connect_site' => in_array('connect_site', $completed, true),
        ];

        return view('app.onboarding.show', [
            'state' => $state,
            'workspace' => $workspace,
            'companyProfile' => $profile,
            'siteCount' => $siteCount,
            'steps' => $steps,
            'intents' => [
                'seo_growth' => 'SEO growth',
                'thought_leadership' => 'Thought leadership',
                'client_content' => 'Client content',
                'internal_kb' => 'Internal knowledge base',
            ],
        ]);
    }

    public function storeIntent(Request $request, OnboardingStateService $states): RedirectResponse
    {
        $data = $request->validate([
            'intent' => ['required', 'in:seo_growth,thought_leadership,client_content,internal_kb'],
        ]);

        $states->markIntent($request->user(), (string) $data['intent']);

        return back()->with('status', 'Intent saved.');
    }

    public function storeCompanyProfile(Request $request, OnboardingStateService $states): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'target_audience' => ['nullable', 'string', 'max:1000'],
            'value_propositions' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $request->user();
        $state = $states->ensureForUser($user);
        $workspace = $this->resolveOnboardingWorkspace($user, $state);

        if (! $workspace) {
            return back()->withErrors(['onboarding' => 'No workspace found.']);
        }

        CompanyProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'company_name' => (string) $data['company_name'],
                'industry' => (string) ($data['industry'] ?? ''),
                'target_audience' => (string) ($data['target_audience'] ?? ''),
                'value_propositions' => (string) ($data['value_propositions'] ?? ''),
            ]
        );

        $states->markCompanyProfileCompleted($user, $workspace);

        return back()->with('status', 'Company profile saved.');
    }

    public function completeSiteConnect(Request $request, OnboardingStateService $states): RedirectResponse
    {
        $activeSites = $request->user()->organization
            ? $request->user()->organization->clientSites()->where('is_active', true)->count()
            : 0;

        if ($activeSites < 1) {
            return back()->withErrors(['onboarding' => 'Connect at least one active site first.']);
        }

        $states->markSiteConnectedCompleted($request->user());

        return back()->with('status', 'Site connection step completed.');
    }

    private function resolveOnboardingWorkspace(\App\Models\User $user, ?OnboardingState $state): ?Workspace
    {
        if ($state?->workspace_id) {
            $workspace = Workspace::query()
                ->where('id', $state->workspace_id)
                ->where('organization_id', $user->organization_id)
                ->first();

            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('created_at')
            ->first();
    }
}
