<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Http\Requests\Admin\ActivateOrganizationRequest;
use App\Http\Requests\Admin\ApproveOrganizationRequest;
use App\Http\Requests\Admin\ArchiveOrganizationRequest;
use App\Http\Requests\Admin\DeactivateOrganizationRequest;
use App\Http\Requests\Admin\DeleteOrganizationRequest;
use App\Http\Requests\Admin\HoldOrganizationRequest;
use App\Http\Requests\Admin\RegenerateOrganizationApiKeyRequest;
use App\Http\Requests\Admin\UnarchiveOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationLegalProfileRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Requests\Admin\UpdateWorkspaceDisplayNameRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Admin\ActivateCustomerAction;
use App\Services\Admin\OrganizationDeletionService;
use App\Services\AuditLogService;
use App\Services\OrganizationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminOrganizationsController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::query()
            ->with(['workspaces' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.organizations.index', [
            'organizations' => $organizations,
        ]);
    }

    public function show(Organization $organization): View
    {
        $organization->load(['users.latestAccessOverride', 'primaryUser', 'workspaces.clientSites', 'accessUpdatedBy']);
        $access = app(OrganizationAccessService::class);

        return view('admin.organizations.show', [
            'organization' => $organization,
            'organizationAccess' => [
                'label' => $access->label($organization),
                'badge_classes' => $access->badgeClasses($organization),
                'is_early_bird_active' => $access->isEarlyBirdActive($organization),
                'is_early_bird_expired' => $access->isEarlyBirdExpired($organization),
            ],
        ]);
    }

    public function grantEarlyBirdAccess(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $data = $request->validate([
            'early_bird_ends_at' => ['required', 'date', 'after_or_equal:today'],
            'early_bird_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $this->accessAuditSnapshot($organization);

        $organization->forceFill([
            'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
            'early_bird_started_at' => $organization->early_bird_started_at ?? now(),
            'early_bird_ends_at' => $data['early_bird_ends_at'],
            'early_bird_note' => trim((string) ($data['early_bird_note'] ?? '')) ?: null,
            'access_updated_by' => $request->user()->id,
        ])->save();

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.access.early_bird_granted',
            before: $before,
            after: $this->accessAuditSnapshot($organization->fresh() ?? $organization),
            request: $request
        );

        return back()->with('status', 'Early Bird access granted.');
    }

    public function extendEarlyBirdAccess(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $data = $request->validate([
            'early_bird_ends_at' => ['required', 'date', 'after_or_equal:today'],
            'early_bird_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $this->accessAuditSnapshot($organization);

        $organization->forceFill([
            'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
            'early_bird_started_at' => $organization->early_bird_started_at ?? now(),
            'early_bird_ends_at' => $data['early_bird_ends_at'],
            'early_bird_note' => trim((string) ($data['early_bird_note'] ?? '')) ?: $organization->early_bird_note,
            'access_updated_by' => $request->user()->id,
        ])->save();

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.access.early_bird_extended',
            before: $before,
            after: $this->accessAuditSnapshot($organization->fresh() ?? $organization),
            request: $request
        );

        return back()->with('status', 'Early Bird access updated.');
    }

    public function endEarlyBirdAccess(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs,
        OrganizationAccessService $access
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $before = $this->accessAuditSnapshot($organization);

        $organization->forceFill([
            'access_tier' => $access->fallbackTierAfterEarlyBird($organization),
            'access_updated_by' => $request->user()->id,
        ])->save();

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.access.early_bird_ended',
            before: $before,
            after: $this->accessAuditSnapshot($organization->fresh() ?? $organization),
            request: $request
        );

        return back()->with('status', 'Early Bird access ended.');
    }

    public function convertToPaidAccess(
        Request $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        abort_unless($request->user()?->is_admin, 403);

        $before = $this->accessAuditSnapshot($organization);

        $organization->forceFill([
            'access_tier' => Organization::ACCESS_TIER_PAID,
            'converted_to_paid_at' => now(),
            'access_updated_by' => $request->user()->id,
        ])->save();

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.access.converted_to_paid',
            before: $before,
            after: $this->accessAuditSnapshot($organization->fresh() ?? $organization),
            request: $request
        );

        return back()->with('status', 'Organization converted to paid access.');
    }

    public function approve(
        ApproveOrganizationRequest $request,
        Organization $organization,
        ActivateCustomerAction $activateCustomer
    ): RedirectResponse
    {
        $activateCustomer->execute($organization, $request->user());

        return back()->with('status', 'Customer activated.');
    }

    public function hold(
        HoldOrganizationRequest $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $before = ['status' => $organization->status];

        $organization->update([
            'status' => Organization::STATUS_ON_HOLD,
        ]);

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.deactivated',
            before: $before,
            after: ['status' => $organization->status],
            request: $request
        );

        return back()->with('status', 'Organization set to on hold.');
    }

    public function archive(
        ArchiveOrganizationRequest $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $this->authorize('archive', $organization);

        $before = ['status' => $organization->status];

        $organization->update([
            'status' => Organization::STATUS_ARCHIVED,
        ]);

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.archived',
            before: $before,
            after: ['status' => $organization->status],
            request: $request
        );

        return back()->with('status', 'Organization archived.');
    }

    public function unarchive(
        UnarchiveOrganizationRequest $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $this->authorize('unarchive', $organization);

        $before = ['status' => $organization->status];

        $organization->update([
            'status' => Organization::STATUS_ON_HOLD,
        ]);

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'organization.unarchived',
            before: $before,
            after: ['status' => $organization->status],
            request: $request
        );

        return back()->with('status', 'Organization restored from archive. It is now on hold and can be activated.');
    }

    public function confirmDelete(
        Organization $organization,
        OrganizationDeletionService $deletionService
    ): View {
        $this->authorize('delete', $organization);

        $deletionCheck = $deletionService->canDelete($organization);
        $relatedData = $deletionService->getRelatedDataSummary($organization);

        return view('admin.organizations.confirm-delete', [
            'organization' => $organization,
            'canDelete' => $deletionCheck['can_delete'],
            'reasons' => $deletionCheck['reasons'],
            'relatedData' => $relatedData,
        ]);
    }

    public function delete(
        DeleteOrganizationRequest $request,
        Organization $organization,
        OrganizationDeletionService $deletionService,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $this->authorize('delete', $organization);

        $deletionCheck = $deletionService->canDelete($organization);
        $forceDelete = (bool) $request->input('force_delete', false);

        // If there are dependencies and force delete is not requested, block
        if (! $deletionCheck['can_delete'] && ! $forceDelete) {
            return back()->withErrors([
                'delete' => 'Organization cannot be deleted because it has related data. Use force delete if you want to permanently remove everything.',
            ]);
        }

        // If force delete is requested, check for forceDelete authorization
        if ($forceDelete && ! $deletionCheck['can_delete']) {
            $this->authorize('forceDelete', $organization);
        }

        // Log before deletion
        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: $forceDelete ? 'organization.force_deleted' : 'organization.deleted',
            before: [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
            ],
            after: null,
            request: $request
        );

        $organizationName = $organization->name;

        // Perform deletion
        $deletionService->forceDelete($organization);

        return redirect()
            ->route('admin.organizations')
            ->with('status', "Organization \"{$organizationName}\" has been permanently deleted.");
    }

    public function activate(
        ActivateOrganizationRequest $request,
        Organization $organization,
        ActivateCustomerAction $activateCustomer
    ): RedirectResponse
    {
        $activateCustomer->execute($organization, $request->user());

        return back()->with('status', 'Customer activated.');
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $data = $request->validated();

        $organization->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'custom_domain' => $data['custom_domain'] ?? null,
            'webhook_url' => $data['webhook_url'] ?? null,
            'api_enabled' => (bool) ($data['api_enabled'] ?? false),
        ]);

        return back()->with('status', 'Organization updated.');
    }

    public function updateLegalProfile(
        UpdateOrganizationLegalProfileRequest $request,
        Organization $organization,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $this->authorize('updateLegalName', $organization);

        $before = [
            'legal_name' => (string) ($organization->legal_name ?? ''),
            'billing_email' => (string) ($organization->billing_email ?? ''),
            'vat_id' => (string) ($organization->vat_id ?? ''),
            'billing_address' => $organization->billing_address,
        ];

        $data = $request->validated();
        $organization->legal_name = $data['legal_name'] ?: null;
        $organization->billing_email = $data['billing_email'] ?: null;
        $organization->vat_id = $data['vat_id'] ?: null;
        $organization->billing_address = [
            'line1' => $data['billing_address_line1'] ?: null,
            'line2' => $data['billing_address_line2'] ?: null,
            'postal_code' => $data['billing_postal_code'] ?: null,
            'city' => $data['billing_city'] ?: null,
            'country_code' => $data['billing_country_code']
                ? strtoupper((string) $data['billing_country_code'])
                : null,
        ];
        $organization->save();

        $after = [
            'legal_name' => (string) ($organization->legal_name ?? ''),
            'billing_email' => (string) ($organization->billing_email ?? ''),
            'vat_id' => (string) ($organization->vat_id ?? ''),
            'billing_address' => $organization->billing_address,
        ];

        $auditLogs->log(
            actor: $request->user(),
            subject: $organization,
            action: 'company.legal_name.updated',
            before: $before,
            after: $after,
            request: $request
        );

        return back()->with('status', 'Company legal profile updated.');
    }

    public function updateWorkspaceDisplayName(
        UpdateWorkspaceDisplayNameRequest $request,
        Organization $organization,
        Workspace $workspace,
        AuditLogService $auditLogs
    ): RedirectResponse {
        if ((int) $workspace->organization_id !== (int) $organization->id) {
            abort(404);
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

    public function regenerateApiKey(RegenerateOrganizationApiKeyRequest $request, Organization $organization): RedirectResponse
    {
        $newApiKey = 'pl_live_' . Str::random(40);

        $organization->setApiKey($newApiKey);
        $organization->save();

        return back()
            ->with('status', 'Organization API key regenerated.')
            ->with('new_api_key', $newApiKey);
    }

    public function impersonateWorkspace(Workspace $workspace, AuditLogService $auditLog): RedirectResponse
    {
        Gate::authorize('admin-area-access');

        return $this->startWorkspaceImpersonation(request(), $workspace, $auditLog);
    }

    public function impersonateOrganization(
        Request $request,
        Organization $organization,
        AuditLogService $auditLog
    ): RedirectResponse {
        Gate::authorize('admin-area-access');

        $organization->loadMissing(['workspaces' => fn ($query) => $query->orderBy('created_at')->orderBy('id')]);

        $workspaceId = trim((string) $request->input('workspace_id', ''));
        $workspace = $workspaceId !== ''
            ? $organization->workspaces->firstWhere('id', $workspaceId)
            : $organization->workspaces->first();

        if (! $workspace) {
            return back()->withErrors(['impersonate' => 'No workspace is available for this organization.']);
        }

        return $this->startWorkspaceImpersonation($request, $workspace, $auditLog, $organization);
    }

    private function startWorkspaceImpersonation(
        Request $request,
        Workspace $workspace,
        AuditLogService $auditLog,
        ?Organization $organization = null
    ): RedirectResponse {
        $admin = $request->user();
        abort_unless($admin?->isAdminAreaUser(), 403);

        if ((bool) $request->session()->get('support_mode_enabled', false)) {
            return back()->withErrors(['impersonate' => 'Stop support mode before starting workspace impersonation.']);
        }

        $organization ??= $workspace->organization()->first();
        if (! $organization) {
            return back()->withErrors(['impersonate' => 'The target organization could not be resolved.']);
        }

        $user = User::query()
            ->where('organization_id', $workspace->organization_id)
            ->where('active', true)
            ->whereNotNull('approved_at')
            ->where('is_admin', false)
            ->orderByRaw("case when role = 'owner' then 0 when role = 'admin' then 1 else 2 end")
            ->first();

        if (! $user) {
            return back()->withErrors(['impersonate' => 'No active user found in this workspace organization.']);
        }

        $wasSwitchingWorkspace = $request->session()->has('admin_impersonator_id');

        $auditLog->log(
            actor: $admin,
            subject: $user,
            action: $wasSwitchingWorkspace ? 'impersonation_switched' : 'impersonation_started',
            before: null,
            after: [
                'workspace_id' => (string) $workspace->id,
                'workspace_name' => $workspace->name,
                'organization_id' => $workspace->organization_id,
                'organization_name' => $organization->name,
            ],
            request: $request
        );

        $request->session()->put('admin_impersonator_id', (string) $admin->id);
        $request->session()->put('impersonated_workspace_id', (string) $workspace->id);
        Auth::login($user);

        return redirect()->route('app.dashboard')
            ->with('status', 'Impersonating workspace through user ' . $user->email . '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function accessAuditSnapshot(Organization $organization): array
    {
        return [
            'access_tier' => (string) ($organization->access_tier ?? ''),
            'early_bird_started_at' => optional($organization->early_bird_started_at)?->toIso8601String(),
            'early_bird_ends_at' => optional($organization->early_bird_ends_at)?->toIso8601String(),
            'early_bird_note' => (string) ($organization->early_bird_note ?? ''),
            'converted_to_paid_at' => optional($organization->converted_to_paid_at)?->toIso8601String(),
            'access_updated_by' => $organization->access_updated_by,
        ];
    }
}
