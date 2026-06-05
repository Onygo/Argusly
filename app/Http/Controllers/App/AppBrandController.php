<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnrichmentRun;
use App\Models\Persona;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppBrandController extends Controller
{
    public function companyProfile(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $organization = $request->user()->organization?->loadMissing('organizationProfile');

        return view('app.brand.company-profile', [
            'workspace' => $workspace,
            'companyProfile' => $workspace?->companyProfile,
            'organizationProfile' => $organization?->organizationProfile,
            'latestBrandContext' => $workspace?->brandContexts()->latest()->first(),
        ]);
    }

    public function voices(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        return view('app.brand.voices', [
            'workspace' => $workspace,
            'brandVoices' => $workspace?->brandVoices()->orderByDesc('is_default')->orderBy('name')->get() ?? collect(),
            'latestBrandContext' => $workspace?->brandContexts()->latest()->first(),
        ]);
    }

    public function personas(Request $request): View
    {
        $organization = $request->user()->organization;

        abort_unless($organization, 403);
        $workspace = $this->resolveWorkspace($request);

        return view('app.brand.personas', [
            'organization' => $organization,
            'workspace' => $workspace,
            'personas' => Persona::query()
                ->where('organization_id', $organization->id)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'latestPersonaRun' => EnrichmentRun::query()
                ->where('organization_id', $organization->id)
                ->where('enrichment_type', EnrichmentRun::TYPE_BUYER_PERSONA)
                ->latest()
                ->first(),
            'latestBrandContext' => $workspace?->brandContexts()->latest()->first(),
        ]);
    }

    public function storePersona(Request $request): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $data = $this->validatePersona($request);

        Persona::query()->create([
            'organization_id' => $organization->id,
            'type' => (string) $data['type'],
            'name' => (string) $data['name'],
            'source_type' => 'manual',
            'source_payload' => ['source' => 'brand_personas_form'],
            'profile_data' => $this->buildPersonaProfileData($data),
            'status' => Persona::STATUS_APPROVED,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Buyer persona created.');
    }

    public function updatePersona(Request $request, Persona $persona): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        abort_unless($organization, 403);
        abort_unless((int) $persona->organization_id === (int) $organization->id, 404);

        $data = $this->validatePersona($request);

        $persona->update([
            'type' => (string) $data['type'],
            'name' => (string) $data['name'],
            'profile_data' => $this->buildPersonaProfileData($data),
            'status' => Persona::STATUS_APPROVED,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Buyer persona updated.');
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            return null;
        }

        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            $workspace = Workspace::query()
                ->with(['companyProfile', 'brandVoices'])
                ->where('organization_id', $organizationId)
                ->whereKey($impersonatedWorkspaceId)
                ->first();

            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->with(['companyProfile', 'brandVoices'])
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePersona(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'in:buyer,user,influencer,decision_maker'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:3000'],
            'goals' => ['nullable', 'string', 'max:3000'],
            'pain_points' => ['nullable', 'string', 'max:3000'],
            'buying_triggers' => ['nullable', 'string', 'max:3000'],
            'objections' => ['nullable', 'string', 'max:3000'],
            'content_preferences' => ['nullable', 'string', 'max:3000'],
            'industry_tags' => ['nullable', 'string', 'max:1000'],
            'seniority_tags' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildPersonaProfileData(array $data): array
    {
        return array_merge(
            Arr::except($data, ['type', 'name', 'goals', 'pain_points', 'buying_triggers', 'objections', 'content_preferences', 'industry_tags', 'seniority_tags']),
            [
                'goals' => $this->splitLines((string) ($data['goals'] ?? '')),
                'pain_points' => $this->splitLines((string) ($data['pain_points'] ?? '')),
                'buying_triggers' => $this->splitLines((string) ($data['buying_triggers'] ?? '')),
                'objections' => $this->splitLines((string) ($data['objections'] ?? '')),
                'content_preferences' => $this->splitLines((string) ($data['content_preferences'] ?? '')),
                'tags' => array_filter([
                    'industry' => $this->splitCommaSeparated((string) ($data['industry_tags'] ?? '')),
                    'seniority' => $this->splitCommaSeparated((string) ($data['seniority_tags'] ?? '')),
                ]),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function splitCommaSeparated(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }
}
