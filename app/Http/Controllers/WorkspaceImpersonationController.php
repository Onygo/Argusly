<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkspaceImpersonationController extends Controller
{
    public function __invoke(Request $request, User $user, CurrentAccountContract $currentAccount): RedirectResponse
    {
        $impersonator = $request->user();
        $account = $currentAccount->get($impersonator);

        abort_unless($impersonator && $account, 403);
        abort_if($impersonator->id === $user->id, 403);
        abort_if($request->session()->has('impersonator_user_id'), 403);
        abort_if($this->hasGlobalPlatformRole($impersonator), 403);

        $targetIsInAccount = $user->memberships()
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->whereHas('account', fn (Builder $query) => $query->where('status', 'active'))
            ->exists();

        abort_unless($targetIsInAccount, 403);

        app(ActivityLogger::class)->log(
            'workspace.user.impersonated',
            "Workspace owner started impersonating {$user->email}.",
            account: $account,
            user: $impersonator,
            subject: $user,
            properties: [
                'target_user_id' => $user->id,
                'account_id' => $account->id,
            ],
        );

        $request->session()->forget(['tenant.current_account_id', 'tenant.current_brand_id']);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_user_id', $impersonator->id);
        $request->session()->put('impersonator_user_name', $impersonator->name);
        $request->session()->put('impersonator_user_email', $impersonator->email);
        $request->session()->put('impersonated_user_id', $user->id);
        $request->session()->put('impersonation_scope', 'workspace');
        $request->session()->put('impersonation_account_id', $account->id);

        return redirect()->route('dashboard')->with('status', "You are now impersonating {$user->name}.");
    }

    private function hasGlobalPlatformRole(User $user): bool
    {
        return $user->roleAssignments()
            ->whereNull('account_id')
            ->whereNull('brand_id')
            ->whereHas('role', fn (Builder $query) => $query->where('name', 'platform_admin'))
            ->exists();
    }
}
