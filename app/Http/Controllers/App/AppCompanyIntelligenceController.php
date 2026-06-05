<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpsertCompanyIntelligenceProfileRequest;
use App\Http\Resources\App\CompanyIntelligenceProfileResource;
use App\Models\BrandVoice;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AppCompanyIntelligenceController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        return view('app.brand.company-intelligence.index', [
            'workspace' => $workspace,
            'profiles' => $workspace?->companyIntelligenceProfiles()
                ->with(['brandVoice'])
                ->orderByDesc('is_default')
                ->orderBy('company_name')
                ->get() ?? collect(),
            'brandVoices' => $workspace?->brandVoices()->orderByDesc('is_default')->orderBy('name')->get() ?? collect(),
            'latestBrandContext' => $workspace?->brandContexts()->latest()->first(),
        ]);
    }

    public function store(
        UpsertCompanyIntelligenceProfileRequest $request,
        CompanyIntelligenceNormalizer $normalizer,
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['company_intelligence' => 'No workspace found for this organization.']);
        }

        $payload = $this->payload($request, $workspace, $normalizer);

        DB::transaction(function () use ($payload): void {
            if ((bool) ($payload['is_default'] ?? false)) {
                CompanyIntelligenceProfile::query()
                    ->where('workspace_id', $payload['workspace_id'])
                    ->update(['is_default' => false]);
            }

            CompanyIntelligenceProfile::query()->create($payload);
        });

        return back()->with('status', 'Company intelligence profile created.');
    }

    public function update(
        UpsertCompanyIntelligenceProfileRequest $request,
        CompanyIntelligenceProfile $profile,
        CompanyIntelligenceNormalizer $normalizer,
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace && (string) $profile->workspace_id === (string) $workspace->id, 404);

        $payload = $this->payload($request, $workspace, $normalizer, $profile);

        DB::transaction(function () use ($profile, $payload): void {
            if ((bool) ($payload['is_default'] ?? false)) {
                CompanyIntelligenceProfile::query()
                    ->where('workspace_id', $profile->workspace_id)
                    ->whereKeyNot($profile->id)
                    ->update(['is_default' => false]);
            }

            $profile->forceFill($payload)->save();
        });

        return back()->with('status', 'Company intelligence profile updated.');
    }

    public function destroy(Request $request, CompanyIntelligenceProfile $profile): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace && (string) $profile->workspace_id === (string) $workspace->id, 404);

        $profile->forceFill([
            'status' => CompanyIntelligenceProfile::STATUS_ARCHIVED,
            'is_default' => false,
            'updated_by' => $request->user()?->id,
        ])->save();

        return back()->with('status', 'Company intelligence profile archived.');
    }

    public function showJson(Request $request, CompanyIntelligenceProfile $profile): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace && (string) $profile->workspace_id === (string) $workspace->id, 404);

        return (new CompanyIntelligenceProfileResource($profile))->response();
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $organizationId = (int) $request->user()?->organization_id;
        if (! $organizationId) {
            return null;
        }

        $impersonatedWorkspaceId = $request->hasSession()
            ? (string) $request->session()->get('impersonated_workspace_id', '')
            : '';
        if ($impersonatedWorkspaceId !== '') {
            $workspace = Workspace::query()
                ->with(['companyProfile', 'brandVoices', 'companyIntelligenceProfiles'])
                ->where('organization_id', $organizationId)
                ->whereKey($impersonatedWorkspaceId)
                ->first();

            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->with(['companyProfile', 'brandVoices', 'companyIntelligenceProfiles'])
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(
        UpsertCompanyIntelligenceProfileRequest $request,
        Workspace $workspace,
        CompanyIntelligenceNormalizer $normalizer,
        ?CompanyIntelligenceProfile $profile = null,
    ): array {
        $data = $request->validated();
        $brandVoiceId = trim((string) ($data['brand_voice_id'] ?? ''));

        if ($brandVoiceId !== '') {
            abort_unless(BrandVoice::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($brandVoiceId)
                ->exists(), 422);
        }

        $base = array_merge($data, [
            'organization_id' => (int) $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'company_profile_id' => $workspace->companyProfile?->id,
            'brand_voice_id' => $brandVoiceId !== '' ? $brandVoiceId : null,
            'brand_key' => Str::slug((string) $data['brand_key'], '_') ?: 'primary',
            'source_type' => $data['source_type'] ?? 'manual',
            'is_default' => $request->boolean('is_default') || ! CompanyIntelligenceProfile::query()
                ->where('workspace_id', $workspace->id)
                ->when($profile, fn ($query) => $query->whereKeyNot($profile->id))
                ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
                ->exists(),
            'updated_by' => $request->user()?->id,
        ]);

        if (! $profile) {
            $base['created_by'] = $request->user()?->id;
        }

        return $normalizer->persistencePayload($base);
    }
}
