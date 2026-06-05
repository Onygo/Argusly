<?php

namespace App\Support\Errors;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Determines whether the current user has admin access for viewing technical details.
 *
 * Supports regular admin/superadmin users as well as impersonation sessions.
 */
class AdminAccessChecker
{
    /**
     * Check if the current user can view admin-level technical details.
     *
     * Returns true if:
     * - The current authenticated user is an admin/superadmin
     * - OR an admin is currently impersonating this session
     */
    public static function canViewTechnicalDetails(?Request $request = null): bool
    {
        $request ??= request();

        // Check if an admin is impersonating
        if ($request->session()->has('admin_impersonator_id')) {
            $impersonatorId = $request->session()->get('admin_impersonator_id');
            $impersonator = User::find($impersonatorId);

            if ($impersonator instanceof User && $impersonator->isAdminAreaUser()) {
                return true;
            }
        }

        // Check if current user is an admin
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isAdminAreaUser();
    }

    /**
     * Check if the current user is a superadmin (highest privilege level).
     */
    public static function isSuperadmin(?Request $request = null): bool
    {
        $request ??= request();

        // Check impersonator first
        if ($request->session()->has('admin_impersonator_id')) {
            $impersonatorId = $request->session()->get('admin_impersonator_id');
            $impersonator = User::find($impersonatorId);

            if ($impersonator instanceof User && $impersonator->isSuperadmin()) {
                return true;
            }
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperadmin();
    }
}
