<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\AcceptInviteRequest;
use App\Http\Requests\App\InviteMemberRequest;
use App\Http\Requests\App\StoreBrandVoiceRequest;
use App\Http\Requests\App\UpdateBrandVoiceRequest;
use App\Http\Requests\App\UpdateNotificationSettingsRequest;
use App\Http\Requests\App\UpdateOrganizationRequest;
use App\Http\Requests\App\UpdateAgenticMarketingExecutionSettingsRequest;
use App\Http\Requests\App\UpdateWorkspaceLanguageSettingsRequest;
use App\Http\Requests\App\UpdateWorkspaceDisplayNameRequest;
use App\Http\Requests\App\UpsertCompanyProfileRequest;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\CompanyProfile;
use App\Models\ContentDestination;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\Enums\SupportedLanguage;
use App\Services\AuditLogService;
use App\Services\Agents\AgentAutomationSettingsResolver;
use App\Services\AgenticMarketing\AutonomyPresetService;
use App\Services\BrandVoiceService;
use App\Services\SubscriptionService;
use App\Support\AdvancedMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class AppSettingsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $legacySection = strtolower(trim((string) $request->query('section', $request->query('tab', ''))));
        if ($legacySection === 'api') {
            return redirect()->route('app.developer.api');
        }

        $organization = $request->user()->organization;
        $workspace = $this->resolveWorkspace($request);

        $notificationSettings = $organization->notification_settings ?? [
            'brief_updates' => true,
            'draft_ready' => true,
            'weekly_summary' => true,
        ];

        $invites = Invite::query()
            ->where('organization_id', $organization->id)
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();

        $users = User::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->get();

        return view('app.settings.index', [
            'organization' => $organization,
            'workspace' => $workspace,
            'canEditWorkspaceName' => $workspace ? $request->user()->can('updateName', $workspace) : false,
            'notificationSettings' => $notificationSettings,
            'workspaceAutomationSettings' => $workspace ? app(AgentAutomationSettingsResolver::class)->forWorkspace($workspace) : [],
            'agenticExecutionSettings' => $workspace ? $this->agenticExecutionSettingsFor($workspace) : null,
            'agenticExecutionSites' => $workspace ? $this->agenticExecutionSitesFor($workspace) : collect(),
            'agenticExecutionDestinations' => $workspace ? $this->agenticExecutionDestinationsFor($workspace) : collect(),
            'autonomyPresets' => app(AutonomyPresetService::class)->presets(),
            'contentLanguageOptions' => SupportedLanguage::options(),
            'users' => $users,
            'invites' => $invites,
        ]);
    }

    public function updateOrganization(UpdateOrganizationRequest $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->ensureManager($request);

        $organization->update([
            'name' => $request->input('name'),
            'custom_domain' => $request->input('custom_domain'),
        ]);

        return back()->with('status', 'Organization settings updated.');
    }

    public function updateWorkspaceName(
        UpdateWorkspaceDisplayNameRequest $request,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['workspace_name' => 'No workspace found for this organization.']);
        }

        $this->authorize('updateName', $workspace);

        $before = [
            'display_name' => (string) $workspace->display_name,
        ];

        $workspace->display_name = trim((string) $request->validated('display_name'));
        $workspace->save();

        $after = [
            'display_name' => (string) $workspace->display_name,
        ];

        $auditLogs->log(
            actor: $request->user(),
            subject: $workspace,
            action: 'workspace.display_name.updated',
            before: $before,
            after: $after,
            request: $request
        );

        return back()->with('status', 'Workspace name updated.');
    }

    public function updateWorkspaceLanguages(UpdateWorkspaceLanguageSettingsRequest $request): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['workspace_languages' => 'No workspace found for this organization.']);
        }

        $this->authorize('updateName', $workspace);

        $workspace->forceFill([
            'default_content_language' => $request->validated('default_content_language'),
            'enabled_content_languages' => array_values(array_unique($request->validated('enabled_content_languages'))),
        ])->save();

        return back()->with('status', 'Workspace language settings updated.');
    }

    public function updateAdvancedMode(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('advanced_mode');

        if ($enabled && ! AdvancedMode::canEnable($request->user())) {
            $request->session()->put(AdvancedMode::SESSION_KEY, false);

            return back()->withErrors([
                'advanced_mode' => 'Advanced Mode is available to workspace owners, admins, and editors.',
            ]);
        }

        $request->session()->put(AdvancedMode::SESSION_KEY, $enabled);

        return back()->with('status', $enabled
            ? 'Advanced Mode enabled. Technical navigation is now visible.'
            : 'Advanced Mode disabled. Technical navigation is hidden.');
    }

    public function updateWorkspaceAgentAutomation(
        Request $request,
        AgentAutomationSettingsResolver $settingsResolver,
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['workspace_automation' => 'No workspace found for this organization.']);
        }

        $this->authorize('updateName', $workspace);

        $settingsResolver->storeWorkspaceSettings($workspace, $this->validatedAgentAutomationSettings($request));

        return back()->with('status', 'Workspace automation settings updated.');
    }

    public function updateAgenticMarketingExecutionSettings(
        UpdateAgenticMarketingExecutionSettingsRequest $request,
        AutonomyPresetService $autonomyPresets
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['agentic_execution_mode' => 'No workspace found for this organization.']);
        }

        $this->authorize('updateName', $workspace);

        $data = $request->validated();
        $existing = AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('brand_voice_id')
            ->first();

        $preset = (string) ($data['autonomy_preset'] ?? $existing?->autonomy_preset ?? AutonomyPresetService::GUIDED_MODE);
        if (array_key_exists('autonomy_preset', $data)) {
            $data = array_merge($data, $autonomyPresets->settingsFor($preset));
        }

        $mode = (string) $data['agentic_execution_mode'];
        $wasAutonomous = $existing?->isAutonomous() ?? false;
        $siteIds = $this->assertAllowedSitesBelongToWorkspace($workspace, (array) ($data['allowed_site_ids'] ?? []));
        $destinationIds = $this->assertAllowedDestinationsBelongToWorkspace($workspace, (array) ($data['allowed_publishing_destination_ids'] ?? []));

        if ($mode === AgenticMarketingExecutionSetting::MODE_AUTONOMOUS) {
            if (! $wasAutonomous && ! $request->boolean('autonomous_opt_in_confirmation')) {
                throw ValidationException::withMessages([
                    'autonomous_opt_in_confirmation' => 'Confirm that this workspace should run in autonomous mode before saving.',
                ]);
            }

            if ($siteIds === []) {
                throw ValidationException::withMessages([
                    'allowed_site_ids' => 'Select at least one publishing site before enabling autonomous Agentic Marketing.',
                ]);
            }

            if (! $this->hasAutonomousCapabilityEnabled($data)) {
                throw ValidationException::withMessages([
                    'agentic_execution_mode' => 'Enable at least one autonomous action type before switching to autonomous mode.',
                ]);
            }
        } else {
            $data = array_merge($data, [
                'autonomous_publication_enabled' => false,
                'autonomous_refresh_enabled' => false,
                'autonomous_internal_linking_enabled' => false,
                'autonomous_brief_generation_enabled' => false,
                'autonomous_chained_plans_enabled' => false,
            ]);
        }

        AgenticMarketingExecutionSetting::query()->updateOrCreate(
            [
                'workspace_id' => (string) $workspace->id,
                'brand_voice_id' => null,
            ],
            [
                'organization_id' => (int) $workspace->organization_id,
                'autonomy_preset' => $preset,
                'agentic_execution_mode' => $mode,
                'autonomous_publication_enabled' => (bool) ($data['autonomous_publication_enabled'] ?? false),
                'autonomous_refresh_enabled' => (bool) ($data['autonomous_refresh_enabled'] ?? false),
                'autonomous_internal_linking_enabled' => (bool) ($data['autonomous_internal_linking_enabled'] ?? false),
                'autonomous_brief_generation_enabled' => (bool) ($data['autonomous_brief_generation_enabled'] ?? false),
                'autonomous_chained_plans_enabled' => (bool) ($data['autonomous_chained_plans_enabled'] ?? false),
                'max_autonomous_actions_per_day' => (int) $data['max_autonomous_actions_per_day'],
                'max_autonomous_credits_per_month' => (int) $data['max_autonomous_credits_per_month'],
                'require_approval_above_priority_score' => (int) $data['require_approval_above_priority_score'],
                'require_approval_for_new_pages' => (bool) ($data['require_approval_for_new_pages'] ?? false),
                'require_approval_for_external_publication' => (bool) ($data['require_approval_for_external_publication'] ?? false),
                'allowed_site_ids' => $siteIds,
                'allowed_publishing_destination_ids' => $destinationIds,
                'notification_email_enabled' => (bool) ($data['notification_email_enabled'] ?? false),
                'updated_by' => $request->user()?->id,
            ],
        );

        return back()->with('status', 'Agentic Marketing execution settings updated.');
    }

    public function updateNotifications(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->ensureManager($request);

        $organization->update([
            'notification_settings' => [
                'brief_updates' => (bool) $request->input('brief_updates', false),
                'draft_ready' => (bool) $request->input('draft_ready', false),
                'weekly_summary' => (bool) $request->input('weekly_summary', false),
            ],
        ]);

        return back()->with('status', 'Notification settings updated.');
    }

    public function invite(InviteMemberRequest $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->ensureManager($request);
        try {
            app(SubscriptionService::class)->assertSeatLimitAvailableForInvite($organization, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['invite' => $exception->getMessage()]);
        }

        $plain = Str::random(48);

        Invite::create([
            'organization_id' => $organization->id,
            'invited_by' => $request->user()->id,
            'email' => $request->input('email'),
            'role' => $request->input('role'),
            'token_hash' => hash('sha256', $plain),
            'token_encrypted' => Crypt::encryptString($plain),
            'expires_at' => now()->addDays(14),
        ]);

        return back()->with('status', 'Invite created. Share the invite link from the table.');
    }

    public function upsertCompanyProfile(UpsertCompanyProfileRequest $request): RedirectResponse
    {
        $this->ensureManager($request);

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['settings' => 'No workspace found for this organization.']);
        }

        CompanyProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            $request->validated(),
        );

        return back()->with('status', 'Company profile saved.');
    }

    public function storeBrandVoice(StoreBrandVoiceRequest $request, BrandVoiceService $brandVoiceService): RedirectResponse
    {
        $this->ensureManager($request);

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace) {
            return back()->withErrors(['settings' => 'No workspace found for this organization.']);
        }

        $data = $request->validated();
        $shouldSetDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);

        $toneOfVoice = (string) ($data['tone_of_voice'] ?? $data['default_tone'] ?? '');
        $writingStyle = (string) ($data['writing_style'] ?? $data['style_guide'] ?? '');
        $doRules = (string) ($data['do_rules'] ?? $data['formatting_rules'] ?? '');
        $dontRules = (string) ($data['dont_rules'] ?? $data['disallowed_terminology'] ?? '');

        $voice = BrandVoice::query()->create(array_merge($data, [
            'workspace_id' => $workspace->id,
            'organization_id' => $workspace->organization_id,
            'tone_of_voice' => $toneOfVoice !== '' ? $toneOfVoice : null,
            'writing_style' => $writingStyle !== '' ? $writingStyle : null,
            'do_rules' => $doRules !== '' ? $doRules : null,
            'dont_rules' => $dontRules !== '' ? $dontRules : null,
            'vocabulary_guidelines' => (string) ($data['preferred_terminology'] ?? ''),
            'default_tone' => (string) ($data['default_tone'] ?? $toneOfVoice) ?: null,
            'style_guide' => (string) ($data['style_guide'] ?? $writingStyle) ?: null,
            'formatting_rules' => (string) ($data['formatting_rules'] ?? $doRules) ?: null,
            'disallowed_terminology' => (string) ($data['disallowed_terminology'] ?? $dontRules) ?: null,
            'example_paragraph' => (string) ($data['example_paragraph'] ?? '') ?: null,
            'is_default' => false,
        ]));

        $hasDefault = BrandVoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->exists();

        if ($shouldSetDefault || ! $hasDefault) {
            $brandVoiceService->setDefault($workspace, (string) $voice->id);
        }

        return back()->with('status', 'Brand voice created.');
    }

    public function updateBrandVoice(
        UpdateBrandVoiceRequest $request,
        BrandVoice $brandVoice,
        BrandVoiceService $brandVoiceService
    ): RedirectResponse {
        $this->ensureManager($request);

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace || (string) $brandVoice->workspace_id !== (string) $workspace->id) {
            abort(404);
        }

        $data = $request->validated();
        $shouldSetDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);
        $toneOfVoice = (string) ($data['tone_of_voice'] ?? $data['default_tone'] ?? $brandVoice->tone_of_voice);
        $writingStyle = (string) ($data['writing_style'] ?? $data['style_guide'] ?? $brandVoice->writing_style);
        $doRules = (string) ($data['do_rules'] ?? $data['formatting_rules'] ?? $brandVoice->do_rules);
        $dontRules = (string) ($data['dont_rules'] ?? $data['disallowed_terminology'] ?? $brandVoice->dont_rules);

        $brandVoice->update(array_merge($data, [
            'organization_id' => $workspace->organization_id,
            'tone_of_voice' => $toneOfVoice !== '' ? $toneOfVoice : null,
            'writing_style' => $writingStyle !== '' ? $writingStyle : null,
            'do_rules' => $doRules !== '' ? $doRules : null,
            'dont_rules' => $dontRules !== '' ? $dontRules : null,
            'vocabulary_guidelines' => (string) ($data['preferred_terminology'] ?? $brandVoice->vocabulary_guidelines) ?: null,
            'default_tone' => (string) ($data['default_tone'] ?? $toneOfVoice) ?: null,
            'style_guide' => (string) ($data['style_guide'] ?? $writingStyle) ?: null,
            'formatting_rules' => (string) ($data['formatting_rules'] ?? $doRules) ?: null,
            'disallowed_terminology' => (string) ($data['disallowed_terminology'] ?? $dontRules) ?: null,
            'example_paragraph' => (string) ($data['example_paragraph'] ?? $brandVoice->example_paragraph) ?: null,
        ]));

        if ($shouldSetDefault) {
            $brandVoiceService->setDefault($workspace, (string) $brandVoice->id);
        }

        return back()->with('status', 'Brand voice updated.');
    }

    public function setDefaultBrandVoice(Request $request, BrandVoice $brandVoice, BrandVoiceService $brandVoiceService): RedirectResponse
    {
        $this->ensureManager($request);

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace || (string) $brandVoice->workspace_id !== (string) $workspace->id) {
            abort(404);
        }

        $brandVoiceService->setDefault($workspace, (string) $brandVoice->id);

        return back()->with('status', 'Default brand voice updated.');
    }

    public function deleteBrandVoice(Request $request, BrandVoice $brandVoice, BrandVoiceService $brandVoiceService): RedirectResponse
    {
        $this->ensureManager($request);

        $workspace = $this->resolveWorkspace($request);
        if (! $workspace || (string) $brandVoice->workspace_id !== (string) $workspace->id) {
            abort(404);
        }

        if ($brandVoice->is_default) {
            $nextDefault = BrandVoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', '!=', $brandVoice->id)
                ->latest()
                ->first();

            if (! $nextDefault) {
                return back()->withErrors([
                    'brand_voices' => 'Cannot delete the default brand voice when no fallback voice exists.',
                ]);
            }

            $brandVoiceService->setDefault($workspace, (string) $nextDefault->id);
        }

        $brandVoice->delete();

        return back()->with('status', 'Brand voice deleted.');
    }

    public function acceptForm(string $token): View
    {
        $invite = $this->resolveInvite($token);

        return view('auth.accept-invite', [
            'token' => $token,
            'invite' => $invite,
        ]);
    }

    public function accept(AcceptInviteRequest $request, string $token): RedirectResponse
    {
        $invite = $this->resolveInvite($token);
        $organization = $invite->organization;

        try {
            app(SubscriptionService::class)->assertSeatLimitAvailable($organization, (string) $invite->invited_by);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['invite' => $exception->getMessage()]);
        }

        User::create([
            'name' => $request->input('name'),
            'email' => $invite->email,
            'password' => Hash::make($request->input('password')),
            'organization_id' => $invite->organization_id,
            'role' => $invite->role,
            'approved_at' => now(),
            'active' => true,
        ]);

        $invite->update(['accepted_at' => now()]);

        return redirect()->route('login')->with('status', 'Invite accepted. Please log in.');
    }

    private function resolveInvite(string $token): Invite
    {
        $hash = hash('sha256', $token);

        $invite = Invite::query()
            ->where('token_hash', $hash)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invite->isExpired()) {
            abort(410);
        }

        return $invite;
    }

    private function ensureManager(Request $request): void
    {
        Gate::authorize('manage-organization');
    }

    /**
     * @return array<string, bool>
     */
    private function validatedAgentAutomationSettings(Request $request): array
    {
        $data = $request->validate([
            'smart_suggestions_enabled' => ['nullable', 'boolean'],
            'automatic_recommendation_generation_enabled' => ['nullable', 'boolean'],
            'automatic_refresh_draft_creation_enabled' => ['nullable', 'boolean'],
            'localization_checks_enabled' => ['nullable', 'boolean'],
        ]);

        return [
            'smart_suggestions_enabled' => (bool) ($data['smart_suggestions_enabled'] ?? false),
            'automatic_recommendation_generation_enabled' => (bool) ($data['automatic_recommendation_generation_enabled'] ?? false),
            'automatic_refresh_draft_creation_enabled' => (bool) ($data['automatic_refresh_draft_creation_enabled'] ?? false),
            'localization_checks_enabled' => (bool) ($data['localization_checks_enabled'] ?? false),
        ];
    }

    private function agenticExecutionSettingsFor(Workspace $workspace): AgenticMarketingExecutionSetting
    {
        return AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('brand_voice_id')
            ->first()
            ?: AgenticMarketingExecutionSetting::defaultsFor($workspace);
    }

    private function agenticExecutionSitesFor(Workspace $workspace)
    {
        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereIn('status', ['active', 'connected']);
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'site_url', 'type', 'status', 'is_active']);
    }

    private function agenticExecutionDestinationsFor(Workspace $workspace)
    {
        return ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'status']);
    }

    /**
     * @param  array<int,string>  $siteIds
     * @return array<int,string>
     */
    private function assertAllowedSitesBelongToWorkspace(Workspace $workspace, array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_filter(array_map('strval', $siteIds))));
        if ($siteIds === []) {
            return [];
        }

        $valid = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $siteIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if (count($valid) !== count($siteIds)) {
            throw ValidationException::withMessages([
                'allowed_site_ids' => 'One or more selected publishing sites do not belong to this workspace.',
            ]);
        }

        return $valid;
    }

    /**
     * @param  array<int,string>  $destinationIds
     * @return array<int,string>
     */
    private function assertAllowedDestinationsBelongToWorkspace(Workspace $workspace, array $destinationIds): array
    {
        $destinationIds = array_values(array_unique(array_filter(array_map('strval', $destinationIds))));
        if ($destinationIds === []) {
            return [];
        }

        $valid = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $destinationIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if (count($valid) !== count($destinationIds)) {
            throw ValidationException::withMessages([
                'allowed_publishing_destination_ids' => 'One or more selected publishing destinations do not belong to this workspace.',
            ]);
        }

        return $valid;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function hasAutonomousCapabilityEnabled(array $data): bool
    {
        foreach ([
            'autonomous_publication_enabled',
            'autonomous_refresh_enabled',
            'autonomous_internal_linking_enabled',
            'autonomous_brief_generation_enabled',
            'autonomous_chained_plans_enabled',
        ] as $key) {
            if ((bool) ($data[$key] ?? false)) {
                return true;
            }
        }

        return false;
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
}
