<?php

namespace App\Events\Onboarding;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DraftGenerated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $draftId)
    {
    }
}
