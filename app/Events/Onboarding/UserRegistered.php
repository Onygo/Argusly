<?php

namespace App\Events\Onboarding;

use Illuminate\Foundation\Events\Dispatchable;

class UserRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $workspaceId = null
    ) {
    }
}
