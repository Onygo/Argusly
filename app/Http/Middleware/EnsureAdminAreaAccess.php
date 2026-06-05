<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAreaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->to(route('login'));
        }

        if (! Gate::forUser($user)->allows('admin-area-access')) {
            $adminImpersonatorId = $request->session()->get('admin_impersonator_id');

            if ($adminImpersonatorId) {
                $admin = User::query()
                    ->whereKey($adminImpersonatorId)
                    ->where('is_admin', true)
                    ->first();

                $request->session()->forget(['admin_impersonator_id', 'impersonated_workspace_id']);

                if ($admin && Gate::forUser($admin)->allows('admin-area-access')) {
                    Auth::login($admin);

                    return redirect()->route('admin.dashboard')
                        ->with('status', 'Impersonation session ended. You are back in your admin account.');
                }

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->to(route('login'))
                    ->withErrors(['impersonation' => 'Impersonation session expired. Please log in again.']);
            }

            abort(403);
        }

        return $next($request);
    }
}
