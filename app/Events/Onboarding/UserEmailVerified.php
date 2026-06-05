<?php

namespace App\Events\Onboarding;

use Illuminate\Foundation\Events\Dispatchable;

class UserEmailVerified
{
    use Dispatchable;

    public function __construct(public readonly int $userId)
    {
    }
}
