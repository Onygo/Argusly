<?php

namespace App\Http\Controllers\Impersonation;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StopImpersonationController extends Controller
{
    public function __invoke(Request $request, AuditLogService $auditLog): RedirectResponse
    {
        $adminId = $request->session()->get('admin_impersonator_id');
        $impersonatedWorkspaceId = $request->session()->get('impersonated_workspace_id');

        if (! $adminId) {
            return back()->withErrors(['impersonation' => 'No active impersonation session found.']);
        }

        $admin = User::query()
            ->whereKey($adminId)
            ->where('is_admin', true)
            ->first();

        if (! $admin) {
            $request->session()->forget(['admin_impersonator_id', 'impersonated_workspace_id']);

            return redirect()->route('login')
                ->withErrors(['impersonation' => 'Original admin account could not be restored.']);
        }

        // Log impersonation end (capture impersonated user before switching back)
        $impersonatedUser = Auth::user();
        if ($impersonatedUser && ! $impersonatedUser->is_admin) {
            $auditLog->log(
                actor: $admin,
                subject: $impersonatedUser,
                action: 'impersonation_ended',
                before: [
                    'workspace_id' => $impersonatedWorkspaceId,
                ],
                after: null,
                request: $request
            );
        }

        Auth::login($admin);
        $request->session()->forget(['admin_impersonator_id', 'impersonated_workspace_id']);

        return redirect()->route('admin.dashboard')
            ->with('status', 'You have returned to your administrator account.');
    }
}
