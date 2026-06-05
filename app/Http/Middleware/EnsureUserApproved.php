<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $organization = $user->organization;

        if (! $organization) {
            return redirect('/');
        }

        if ($organization->status === 'on_hold') {
            // Use relative path for cross-subdomain compatibility
            return redirect()->to('/on-hold');
        }

        if ($user->active === false) {
            auth()->logout();
            if (app()->bound('session')) {
                session()->invalidate();
                session()->regenerateToken();
            }

            // Use relative path for cross-subdomain compatibility
            return redirect()->to('/pending')->with('status', 'Account pending activation by admin.');
        }

        if (! $user->isApproved()) {
            // Use relative path for cross-subdomain compatibility
            return redirect()->to('/pending');
        }

        return $next($request);
    }
}
