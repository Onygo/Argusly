<?php

namespace App\Events\Onboarding;

use Illuminate\Foundation\Events\Dispatchable;

class BriefCreated
{
    use Dispatchable;

    public function __construct(public readonly string $briefId)
    {
    }
}
