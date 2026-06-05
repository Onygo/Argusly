<?php

namespace App\Observers;

use App\Events\Onboarding\UserEmailVerified;
use App\Models\User;

class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('email_verified_at') && $user->email_verified_at !== null) {
            UserEmailVerified::dispatch($user->id);
        }
    }
}

