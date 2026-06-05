<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Support\SupportContext;
use App\Services\Support\SupportSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminSupportModeController extends Controller
{
    public function index(Request $request, SupportContext $support): View
    {
        Gate::authorize('admin-area-superadmin');

        $organizations = Organization::query()->orderBy('name')->get(['id', 'name']);
        $users = User::query()
            ->where('is_admin', false)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'organization_id', 'active']);

        $summary = null;
        $targetCompany = $support->targetCompany();
        $targetUser = $support->targetUser();
        if ($targetCompany && $targetUser) {
            $workspaceIds = $targetCompany->workspaces()->pluck('id')->all();
            $siteIds = \App\Models\ClientSite::query()->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();

            $summary = [
                'user_role' => (string) $targetUser->role,
                'sites_count' => count($siteIds),
                'drafts_count' => \App\Models\Draft::query()->whereIn('client_site_id', $siteIds)->count(),
                'briefs_count' => \App\Models\Brief::query()->whereIn('client_site_id', $siteIds)->count(),
                'last_login_at' => optional($targetUser->last_login_at)->toDateTimeString(),
                'plan' => \App\Models\Subscription::query()
                    ->where('organization_id', $targetCompany->id)
                    ->with('plan')
                    ->latest('updated_at')
                    ->first()?->plan?->name ?? 'n/a',
            ];
        }

        return view('admin.support.index', [
            'organizations' => $organizations,
            'users' => $users,
            'support' => $support,
            'summary' => $summary,
        ]);
    }

    public function start(Request $request, SupportContext $support, AuditLogService $auditLogs): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        if ($support->isEnabled()) {
            return back()->withErrors(['support' => 'Stop active support mode before starting a new one.']);
        }

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:organizations,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $company = Organization::query()->findOrFail((int) $data['company_id']);
        $targetUser = User::query()->findOrFail((int) $data['user_id']);

        if ((int) $targetUser->organization_id !== (int) $company->id) {
            return back()->withErrors(['support' => 'Target user does not belong to selected company.']);
        }

        if ($targetUser->isSuperadmin()) {
            return back()->withErrors(['support' => 'Support mode cannot target a superadmin user.']);
        }

        $request->session()->put([
            'support_mode_enabled' => true,
            'support_target_company_id' => $company->id,
            'support_target_user_id' => $targetUser->id,
            'support_started_by_admin_id' => $request->user()->id,
            'support_started_at' => now()->toIso8601String(),
            'support_reason' => trim((string) ($data['reason'] ?? '')),
        ]);

        $auditLogs->log(
            actor: $request->user(),
            subject: $targetUser,
            action: 'support_mode.started',
            before: null,
            after: [
                'target_user_id' => $targetUser->id,
                'target_company_id' => $company->id,
                'reason' => trim((string) ($data['reason'] ?? '')),
            ],
            request: $request
        );

        return redirect()->route('admin.support.index')->with('status', 'Support mode started.');
    }

    public function stop(Request $request, SupportContext $support, AuditLogService $auditLogs): RedirectResponse
    {
        Gate::authorize('admin-area-superadmin');

        $targetUser = $support->targetUser();
        if ($support->isEnabled() && $targetUser) {
            $auditLogs->log(
                actor: $request->user(),
                subject: $targetUser,
                action: 'support_mode.stopped',
                before: null,
                after: [
                    'target_user_id' => $targetUser->id,
                    'target_company_id' => $support->targetCompany()?->id,
                ],
                request: $request
            );
        }

        $support->clear($request);

        return redirect()->route('admin.support.index')->with('status', 'Support mode stopped.');
    }

    public function diagnostics(
        Request $request,
        SupportContext $support,
        SupportSnapshotService $snapshots,
        AuditLogService $auditLogs
    ): JsonResponse {
        Gate::authorize('admin-area-superadmin');

        abort_unless($support->isEnabled() && $support->targetCompany() && $support->targetUser(), 403);

        $payload = $snapshots->diagnostics($support->targetCompany(), $support->targetUser());

        $auditLogs->log(
            actor: $request->user(),
            subject: $support->targetUser(),
            action: 'support_mode.diagnostics.viewed',
            before: null,
            after: [
                'target_user_id' => $support->targetUser()->id,
                'target_company_id' => $support->targetCompany()->id,
            ],
            request: $request
        );

        return response()->json($payload);
    }

    public function snapshot(
        Request $request,
        SupportContext $support,
        SupportSnapshotService $snapshots,
        AuditLogService $auditLogs
    ) {
        Gate::authorize('admin-area-superadmin');

        abort_unless($support->isEnabled() && $support->targetCompany() && $support->targetUser(), 403);

        $generated = $snapshots->generateSnapshot($support->targetCompany(), $support->targetUser());

        $auditLogs->log(
            actor: $request->user(),
            subject: $support->targetUser(),
            action: 'support_mode.snapshot.generated',
            before: null,
            after: [
                'target_user_id' => $support->targetUser()->id,
                'target_company_id' => $support->targetCompany()->id,
                'path' => $generated['path'],
            ],
            request: $request
        );

        return response()->download(
            Storage::disk('local')->path($generated['path']),
            $generated['filename'],
            ['Content-Type' => 'application/json']
        );
    }
}
