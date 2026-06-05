<?php

namespace App\Http\Middleware;

use App\Services\Support\SupportContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $support = app(SupportContext::class);
        $supportEnabled = $support->isEnabled();

        if ($user && $user->is_admin) {
            // Allow superadmins in app routes only while support mode is active.
            if ($supportEnabled && $user->isSuperadmin()) {
                return $next($request);
            }

            if ($request->session()->has('admin_impersonator_id')) {
                $request->session()->forget(['admin_impersonator_id', 'impersonated_workspace_id']);

                return redirect()->route('admin.dashboard')
                    ->withErrors(['impersonation' => 'Impersonation session became invalid and was stopped.']);
            }

            return redirect()->route('admin.dashboard');
        }

        if (! $user || ! $user->organization_id) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        return $next($request);
    }
}
