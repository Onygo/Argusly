<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdvancedMode
{
    public const SESSION_KEY = 'app_advanced_mode_enabled';

    public static function canEnable(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->is_admin || in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public static function enabled(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        return filter_var($request->session()->get(self::SESSION_KEY, false), FILTER_VALIDATE_BOOL)
            && self::canEnable($request->user());
    }

    public static function canSeeWorkflowInternals(?User $user): bool
    {
        return self::canEnable($user);
    }

    public static function canSeeOrganizationControls(?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-organization');
    }

    public static function canSeeDeveloperTools(?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-organization');
    }
}
